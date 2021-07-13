#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include <SAPI.h>
#include <Zend/zend.h>
#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_extensions.h>
#include <Zend/zend_smart_str.h>
#include <Zend/zend_vm.h>
#include <headers/headers.h>
#include <inttypes.h>
#include <php.h>
#include <php_ini.h>
#include <php_main.h>
#include <stdatomic.h>

#include <ext/spl/spl_exceptions.h>
#include <ext/standard/info.h>

#include "arrays.h"
#include "auto_flush.h"
#include "circuit_breaker.h"
#include "comms_php.h"
#include "compat_string.h"
#include "compatibility.h"
#include "coms.h"
#include "configuration.h"
#include "configuration_php_iface.h"
#include "ddshared.h"
#include "ddtrace.h"
#include "ddtrace_string.h"
#include "dispatch.h"
#include "dogstatsd_client.h"
#include "engine_hooks.h"
#include "excluded_modules.h"
#include "handlers_internal.h"
#include "integrations/integrations.h"
#include "logging.h"
#include "memory_limit.h"
#include "random.h"
#include "request_hooks.h"
#include "sapi/sapi.h"
#include "serializer.h"
#include "signals.h"
#include "span.h"
#include "startup_logging.h"

bool ddtrace_has_excluded_module;

atomic_int ddtrace_first_rinit;
atomic_int ddtrace_warn_legacy_api;

ZEND_DECLARE_MODULE_GLOBALS(ddtrace)

PHP_INI_BEGIN()
STD_PHP_INI_BOOLEAN("ddtrace.disable", "0", PHP_INI_SYSTEM, OnUpdateBool, disable, zend_ddtrace_globals,
                    ddtrace_globals)
#if _BUILD_FROM_PECL_
STD_PHP_INI_ENTRY("ddtrace.request_init_hook", "@php_dir@/datadog_trace/bridge/dd_wrap_autoloader.php", PHP_INI_SYSTEM,
                  OnUpdateString, request_init_hook, zend_ddtrace_globals, ddtrace_globals)
#else
STD_PHP_INI_ENTRY("ddtrace.request_init_hook", "", PHP_INI_SYSTEM, OnUpdateString, request_init_hook,
                  zend_ddtrace_globals, ddtrace_globals)
#endif

// Exposed for testing only
STD_PHP_INI_ENTRY("ddtrace.cgroup_file", "/proc/self/cgroup", PHP_INI_SYSTEM, OnUpdateString, cgroup_file,
                  zend_ddtrace_globals, ddtrace_globals)
PHP_INI_END()

static int ddtrace_startup(struct _zend_extension *extension) {
    UNUSED(extension);
    ddtrace_resource = zend_get_resource_handle(PHP_DDTRACE_EXTNAME);
    ddtrace_op_array_extension = zend_get_op_array_extension_handle(PHP_DDTRACE_EXTNAME);

    ddtrace_excluded_modules_startup();
    ddtrace_internal_handlers_startup();
    return SUCCESS;
}

static void ddtrace_shutdown(struct _zend_extension *extension) {
    UNUSED(extension);

    ddtrace_internal_handlers_shutdown();
}

static void ddtrace_activate(void) {}
static void ddtrace_deactivate(void) {}

static zend_extension _dd_zend_extension_entry = {"ddtrace",
                                                  PHP_DDTRACE_VERSION,
                                                  "Datadog",
                                                  "https://github.com/DataDog/dd-trace-php",
                                                  "Copyright Datadog",
                                                  ddtrace_startup,
                                                  ddtrace_shutdown,
                                                  ddtrace_activate,
                                                  ddtrace_deactivate,
                                                  NULL,
                                                  NULL,
                                                  NULL,
                                                  NULL,
                                                  NULL,
                                                  NULL,
                                                  NULL,

                                                  STANDARD_ZEND_EXTENSION_PROPERTIES};

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_trace_method, 0, 0, 3)
ZEND_ARG_INFO(0, class_name)
ZEND_ARG_INFO(0, method_name)
ZEND_ARG_INFO(0, tracing_closure)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_hook_method, 0, 0, 2)
ZEND_ARG_INFO(0, class_name)
ZEND_ARG_INFO(0, method_name)
ZEND_ARG_INFO(0, prehook)
ZEND_ARG_INFO(0, posthook)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_hook_function, 0, 0, 1)
ZEND_ARG_INFO(0, function_name)
ZEND_ARG_INFO(0, prehook)
ZEND_ARG_INFO(0, posthook)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_trace_function, 0, 0, 2)
ZEND_ARG_INFO(0, function_name)
ZEND_ARG_INFO(0, tracing_closure)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace_serialize_msgpack, 0, 0, 1)
ZEND_ARG_INFO(0, trace_array)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace_buffer_span, 0, 0, 1)
ZEND_ARG_INFO(0, trace_array)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace_env_config, 0, 0, 1)
ZEND_ARG_INFO(0, env_name)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace_set_trace_id, 0, 0, 1)
ZEND_ARG_INFO(0, trace_id)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace_push_span_id, 0, 0, 0)
ZEND_ARG_INFO(0, existing_id)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace_start_span, 0, 0, 0)
ZEND_ARG_INFO(0, start_time)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace_close_span, 0, 0, 0)
ZEND_ARG_INFO(0, finish_time)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_add_global_tag, 0, 0, 2)
ZEND_ARG_INFO(0, key)
ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace_internal_fn, 0, 0, 1)
ZEND_ARG_INFO(0, function_name)
ZEND_ARG_VARIADIC_INFO(0, vars)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace_send_traces_via_thread, 0, 0, 3)
ZEND_ARG_INFO(0, url)
ZEND_ARG_INFO(0, http_headers)
ZEND_ARG_INFO(0, body)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_untrace, 0, 0, 1)
ZEND_ARG_INFO(0, function_name)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_config_app_name, 0, 0, 0)
ZEND_ARG_INFO(0, default_name)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_config_integration_enabled, 0, 0, 1)
ZEND_ARG_INFO(0, integration_name)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_config_integration_analytics_enabled, 0, 0, 1)
ZEND_ARG_INFO(0, integration_name)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_config_integration_analytics_sample_rate, 0, 0, 1)
ZEND_ARG_INFO(0, integration_name)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_testing_trigger_error, 0, 0, 2)
ZEND_ARG_INFO(0, level)
ZEND_ARG_INFO(0, message)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_init, 0, 0, 1)
ZEND_ARG_INFO(0, dir)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_void, 0, 0, 0)
ZEND_END_ARG_INFO()

/* Legacy API */
ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace, 0, 0, 2)
ZEND_ARG_INFO(0, class_or_function_name)
ZEND_ARG_INFO(0, method_name_or_tracing_closure)
ZEND_ARG_INFO(0, tracing_closure)
ZEND_END_ARG_INFO()

static void php_ddtrace_init_globals(zend_ddtrace_globals *ng) { memset(ng, 0, sizeof(zend_ddtrace_globals)); }

static PHP_GINIT_FUNCTION(ddtrace) {
#if defined(COMPILE_DL_DDTRACE) && defined(ZTS)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    php_ddtrace_init_globals(ddtrace_globals);
}

/* DDTrace\SpanData */
zend_class_entry *ddtrace_ce_span_data;
zend_object_handlers ddtrace_span_data_handlers;

static zend_object *ddtrace_span_data_create(zend_class_entry *class_type) {
    ddtrace_span_fci *span_fci = ecalloc(1, sizeof(*span_fci));
    zend_object_std_init(&span_fci->span.std, class_type);
    span_fci->span.std.handlers = &ddtrace_span_data_handlers;
    return &span_fci->span.std;
}

static void ddtrace_span_data_free_storage(zend_object *object) {
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)object;
    if (span_fci->exception) {
        OBJ_RELEASE(span_fci->exception);
    }
    if (span_fci->dispatch) {
        ddtrace_dispatch_release(span_fci->dispatch);
        span_fci->dispatch = NULL;
    }
    zend_object_std_dtor(object);
}

static zend_object *ddtrace_span_data_clone_obj(zend_object *old_obj) {
    zend_object *new_obj = ddtrace_span_data_create(old_obj->ce);
    zend_objects_clone_members(new_obj, old_obj);
    return new_obj;
}

static PHP_METHOD(DDTrace_SpanData, getDuration) {
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)Z_OBJ_P(ZEND_THIS);
    RETURN_LONG(span_fci->span.duration);
}

static PHP_METHOD(DDTrace_SpanData, getStartTime) {
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)Z_OBJ_P(ZEND_THIS);
    RETURN_LONG(span_fci->span.start);
}

const zend_function_entry class_DDTrace_SpanData_methods[] = {
    // clang-format off
    PHP_ME(DDTrace_SpanData, getDuration, arginfo_ddtrace_void, ZEND_ACC_PUBLIC)
    PHP_ME(DDTrace_SpanData, getStartTime, arginfo_ddtrace_void, ZEND_ACC_PUBLIC)
    PHP_FE_END
    // clang-format on
};

static void dd_register_span_data_ce(void) {
    memcpy(&ddtrace_span_data_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    ddtrace_span_data_handlers.clone_obj = ddtrace_span_data_clone_obj;
    ddtrace_span_data_handlers.free_obj = ddtrace_span_data_free_storage;

    zend_class_entry ce_span_data;
    INIT_NS_CLASS_ENTRY(ce_span_data, "DDTrace", "SpanData", class_DDTrace_SpanData_methods);
    ddtrace_ce_span_data = zend_register_internal_class(&ce_span_data);
    ddtrace_ce_span_data->create_object = ddtrace_span_data_create;

    // trace_id, span_id, parent_id, start & duration are stored directly on
    // ddtrace_span_t so we don't need to make them properties on DDTrace\SpanData
    /*
     * ORDER MATTERS: If you make any changes to the properties below, update the
     * corresponding ddtrace_spandata_property_*() function with the proper offset.
     * ALSO: Update the properties_table_placeholder size of ddtrace_span_t to property count - 1.
     */
    zend_declare_property_null(ddtrace_ce_span_data, "name", sizeof("name") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "resource", sizeof("resource") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "service", sizeof("service") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "type", sizeof("type") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "meta", sizeof("meta") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "metrics", sizeof("metrics") - 1, ZEND_ACC_PUBLIC);
}

#pragma GCC diagnostic push
#pragma GCC diagnostic ignored "-Warray-bounds"  // useful compiler does not like the struct hack
// SpanData::$name
zval *ddtrace_spandata_property_name(ddtrace_span_t *span) { return OBJ_PROP_NUM(&span->std, 0); }
// SpanData::$resource
zval *ddtrace_spandata_property_resource(ddtrace_span_t *span) { return OBJ_PROP_NUM(&span->std, 1); }
// SpanData::$service
zval *ddtrace_spandata_property_service(ddtrace_span_t *span) { return OBJ_PROP_NUM(&span->std, 2); }
// SpanData::$type
zval *ddtrace_spandata_property_type(ddtrace_span_t *span) { return OBJ_PROP_NUM(&span->std, 3); }
// SpanData::$meta
zval *ddtrace_spandata_property_meta(ddtrace_span_t *span) { return OBJ_PROP_NUM(&span->std, 4); }
// SpanData::$metrics
zval *ddtrace_spandata_property_metrics(ddtrace_span_t *span) { return OBJ_PROP_NUM(&span->std, 5); }
#pragma GCC diagnostic pop

BOOL_T ddtrace_fetch_prioritySampling_from_root(int *priority) {
    zval *priority_zv;
    ddtrace_span_fci *root_span = DDTRACE_G(open_spans_top);

    if (!root_span) {
        return false;
    }

    while (root_span->next) {
        root_span = root_span->next;
    }

    zval *root_metrics = ddtrace_spandata_property_metrics(&root_span->span);
    ZVAL_DEREF(root_metrics);
    if (Z_TYPE_P(root_metrics) != IS_ARRAY) {
        return false;
    }

    if (!(priority_zv = zend_hash_str_find(Z_ARR_P(root_metrics), ZEND_STRL("_sampling_priority_v1")))) {
        return false;
    }

    *priority = (int)zval_get_long(priority_zv);
    return true;
}

/* DDTrace\FatalError */
zend_class_entry *ddtrace_ce_fatal_error;

static void dd_register_fatal_error_ce(void) {
    zend_class_entry ce;
    INIT_NS_CLASS_ENTRY(ce, "DDTrace", "FatalError", NULL);
    ddtrace_ce_fatal_error = zend_register_internal_class_ex(&ce, zend_ce_exception);
}

static bool dd_is_compatible_sapi(datadog_php_string_view module_name) {
    switch (datadog_php_sapi_from_name(module_name)) {
        case DATADOG_PHP_SAPI_APACHE2HANDLER:
        case DATADOG_PHP_SAPI_CGI_FCGI:
        case DATADOG_PHP_SAPI_CLI:
        case DATADOG_PHP_SAPI_CLI_SERVER:
        case DATADOG_PHP_SAPI_FPM_FCGI:
            return true;

        default:
            return false;
    }
}

static void dd_disable_if_incompatible_sapi_detected(void) {
    datadog_php_string_view module_name = datadog_php_string_view_from_cstr(sapi_module.name);
    if (UNEXPECTED(!dd_is_compatible_sapi(module_name))) {
        ddtrace_log_debugf("Incompatible SAPI detected '%s'; disabling ddtrace", sapi_module.name);
        DDTRACE_G(disable) = 1;
    }
}

static void dd_read_distributed_tracing_ids(void);

static PHP_MINIT_FUNCTION(ddtrace) {
    UNUSED(type);
    REGISTER_STRING_CONSTANT("DD_TRACE_VERSION", PHP_DDTRACE_VERSION, CONST_CS | CONST_PERSISTENT);
    REGISTER_INI_ENTRIES();

    // config initialization needs to be at the top
    ddtrace_initialize_config();
    dd_disable_if_incompatible_sapi_detected();
    atomic_init(&ddtrace_first_rinit, 1);
    atomic_init(&ddtrace_warn_legacy_api, 1);

    /* This allows an extension (e.g. extension=ddtrace.so) to have zend_engine
     * hooks too, but not loadable as zend_extension=ddtrace.so.
     * See http://www.phpinternalsbook.com/php7/extensions_design/zend_extensions.html#hybrid-extensions
     * {{{ */
    Dl_info infos;
    zend_register_extension(&_dd_zend_extension_entry, ddtrace_module_entry.handle);
    dladdr(ZEND_MODULE_STARTUP_N(ddtrace), &infos);
    dlopen(infos.dli_fname, RTLD_LAZY);
    /* }}} */

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    ddtrace_bgs_log_minit();

    ddtrace_dogstatsd_client_minit();
    ddtrace_signals_minit();
    ddshared_minit();

    dd_register_span_data_ce();
    dd_register_fatal_error_ce();

    ddtrace_engine_hooks_minit();

    ddtrace_coms_minit();
    ddtrace_coms_init_and_start_writer();

    ddtrace_integrations_minit();

    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

    UNREGISTER_INI_ENTRIES();

    if (DDTRACE_G(disable)) {
        ddtrace_config_shutdown();
        return SUCCESS;
    }

    ddtrace_integrations_mshutdown();

    ddtrace_signals_mshutdown();

    ddtrace_coms_mshutdown();
    if (ddtrace_coms_flush_shutdown_writer_synchronous()) {
        ddtrace_coms_curl_shutdown();
        // if writer is ensured to be shutdown we can free up config resources safely
        ddtrace_config_shutdown();

        ddtrace_bgs_log_mshutdown();
    }

    ddtrace_engine_hooks_mshutdown();

    return SUCCESS;
}

static PHP_RINIT_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

    if (ddtrace_has_excluded_module == true) {
        DDTRACE_G(disable) = 1;
    }

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    array_init_size(&DDTRACE_G(additional_trace_meta), ddtrace_num_error_tags);
    DDTRACE_G(additional_global_tags) = zend_new_array(0);

    // Things that should only run on the first RINIT
    int expected_first_rinit = 1;
    if (atomic_compare_exchange_strong(&ddtrace_first_rinit, &expected_first_rinit, 0)) {
        /* The env vars are memoized on MINIT before the SAPI env vars are available.
         * We use the first RINIT to bust the env var cache and use the SAPI env vars.
         * TODO Audit/remove config usages before RINIT and move config init to RINIT.
         */
        ddtrace_reload_config();

        ddtrace_startup_logging_first_rinit();
    }

    DDTRACE_G(request_init_hook_loaded) = 0;
    if (DDTRACE_G(request_init_hook) && DDTRACE_G(request_init_hook)[0]) {
        dd_request_init_hook_rinit();
    }

    ddtrace_internal_handlers_rinit();
    ddtrace_bgs_log_rinit(PG(error_log));
    ddtrace_dispatch_init();
    DDTRACE_G(disable_in_current_request) = 0;
    DDTRACE_G(drop_all_spans) = 0;

    ddtrace_dogstatsd_client_rinit();

    ddtrace_seed_prng();
    ddtrace_init_span_id_stack();
    ddtrace_init_span_stacks();
    ddtrace_coms_on_pid_change();

    // Initialize C integrations and deferred loading
    ddtrace_integrations_rinit();

    // Reset compile time after request init hook has compiled
    ddtrace_compile_time_reset();

    dd_prepare_for_new_trace();

    dd_read_distributed_tracing_ids();

    if (get_dd_trace_generate_root_span()) {
        ddtrace_push_root_span();
    }

    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    ddtrace_close_all_open_spans();  // All remaining non-internal userland spans
    if (DDTRACE_G(open_spans_top) && DDTRACE_G(open_spans_top)->execute_data == NULL) {
        // we have a root span. Close it.
        dd_trace_stop_span_time(&DDTRACE_G(open_spans_top)->span);
        ddtrace_close_span(DDTRACE_G(open_spans_top));
    }
    if (ddtrace_flush_tracer() == FAILURE) {
        ddtrace_log_debug("Unable to flush the tracer");
    }

    zval_dtor(&DDTRACE_G(additional_trace_meta));
    zend_array_destroy(DDTRACE_G(additional_global_tags));
    ZVAL_NULL(&DDTRACE_G(additional_trace_meta));

    ddtrace_internal_handlers_rshutdown();
    ddtrace_dogstatsd_client_rshutdown();

    ddtrace_dispatch_destroy();
    ddtrace_free_span_id_stack();
    ddtrace_free_span_stacks();
    ddtrace_coms_rshutdown();

    if (DDTRACE_G(request_init_hook) && DDTRACE_G(request_init_hook)[0]) {
        dd_request_init_hook_rshutdown();
    }

    return SUCCESS;
}

static int datadog_info_print(const char *str) { return php_output_write(str, strlen(str)); }

static void _dd_info_tracer_config(void) {
    smart_str buf = {0};
    ddtrace_startup_logging_json(&buf);
    php_info_print_table_row(2, "DATADOG TRACER CONFIGURATION", ZSTR_VAL(buf.s));
    smart_str_free(&buf);
}

static void _dd_info_diagnostics_row(const char *key, const char *value) {
    if (sapi_module.phpinfo_as_text) {
        php_info_print_table_row(2, key, value);
        return;
    }
    datadog_info_print("<tr><td class='e'>");
    datadog_info_print(key);
    datadog_info_print("</td><td class='v' style='background-color:#f0e881;'>");
    datadog_info_print(value);
    datadog_info_print("</td></tr>");
}

static void _dd_info_diagnostics_table(void) {
    php_info_print_table_start();
    php_info_print_table_colspan_header(2, "Diagnostics");

    HashTable *ht;
    ALLOC_HASHTABLE(ht);
    zend_hash_init(ht, 8, NULL, ZVAL_PTR_DTOR, 0);

    ddtrace_startup_diagnostics(ht, false);

    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(ht, key, val) {
        switch (Z_TYPE_P(val)) {
            case IS_STRING:
                _dd_info_diagnostics_row(ZSTR_VAL(key), Z_STRVAL_P(val));
                break;
            case IS_NULL:
                _dd_info_diagnostics_row(ZSTR_VAL(key), "NULL");
                break;
            case IS_TRUE:
            case IS_FALSE:
                _dd_info_diagnostics_row(ZSTR_VAL(key), Z_TYPE_P(val) == IS_TRUE ? "true" : "false");
                break;
            default:
                _dd_info_diagnostics_row(ZSTR_VAL(key), "{unknown type}");
                break;
        }
    }
    ZEND_HASH_FOREACH_END();

    php_info_print_table_row(2, "Diagnostic checks", zend_hash_num_elements(ht) == 0 ? "passed" : "failed");

    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);

    php_info_print_table_end();
}

static PHP_MINFO_FUNCTION(ddtrace) {
    UNUSED(zend_module);

    php_info_print_box_start(0);
    datadog_info_print("Datadog PHP tracer extension");
    if (!sapi_module.phpinfo_as_text) {
        datadog_info_print("<br><strong>For help, check out ");
        datadog_info_print(
            "<a href=\"https://docs.datadoghq.com/tracing/languages/php/\" "
            "style=\"background:transparent;\">the documentation</a>.</strong>");
    } else {
        datadog_info_print(
            "\nFor help, check out the documentation at "
            "https://docs.datadoghq.com/tracing/languages/php/");
    }
    datadog_info_print(!sapi_module.phpinfo_as_text ? "<br><br>" : "\n");
    datadog_info_print("(c) Datadog 2020\n");
    php_info_print_box_end();

    php_info_print_table_start();
    php_info_print_table_row(2, "Datadog tracing support", DDTRACE_G(disable) ? "disabled" : "enabled");
    php_info_print_table_row(2, "Version", PHP_DDTRACE_VERSION);
    _dd_info_tracer_config();
    php_info_print_table_end();

    _dd_info_diagnostics_table();

    DISPLAY_INI_ENTRIES();
}

static BOOL_T _parse_config_array(zval *config_array, zval **tracing_closure, uint32_t *options) {
    if (Z_TYPE_P(config_array) != IS_ARRAY) {
        ddtrace_log_debug("Expected config_array to be an associative array");
        return FALSE;
    }

    zval *value;
    zend_string *key;

    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(Z_ARRVAL_P(config_array), key, value) {
        if (!key) {
            ddtrace_log_debug("Expected config_array to be an associative array");
            return FALSE;
        }
        // TODO Optimize this
        if (strcmp("posthook", ZSTR_VAL(key)) == 0) {
            if (Z_TYPE_P(value) == IS_OBJECT && instanceof_function(Z_OBJCE_P(value), zend_ce_closure)) {
                *tracing_closure = value;
                *options |= DDTRACE_DISPATCH_POSTHOOK;
            } else {
                ddtrace_log_debugf("Expected '%s' to be an instance of Closure", ZSTR_VAL(key));
                return FALSE;
            }
        } else if (strcmp("prehook", ZSTR_VAL(key)) == 0) {
            if (Z_TYPE_P(value) == IS_OBJECT && instanceof_function(Z_OBJCE_P(value), zend_ce_closure)) {
                *tracing_closure = value;
                *options |= DDTRACE_DISPATCH_PREHOOK;
            } else {
                ddtrace_log_debugf("Expected '%s' to be an instance of Closure", ZSTR_VAL(key));
                return FALSE;
            }
        } else if (strcmp("instrument_when_limited", ZSTR_VAL(key)) == 0) {
            if (Z_TYPE_P(value) == IS_LONG) {
                if (Z_LVAL_P(value)) {
                    *options |= DDTRACE_DISPATCH_INSTRUMENT_WHEN_LIMITED;
                }
            } else {
                ddtrace_log_debugf("Expected '%s' to be an int", ZSTR_VAL(key));
                return FALSE;
            }
        } else {
            ddtrace_log_debugf("Unknown option '%s' in config_array", ZSTR_VAL(key));
            return FALSE;
        }
    }
    ZEND_HASH_FOREACH_END();
    if (!*tracing_closure) {
        ddtrace_log_debug("Required key 'posthook' or 'prehook' not found in config_array");
        return FALSE;
    }
    return TRUE;
}

static bool ddtrace_should_warn_legacy(void) {
    int expected = 1;
    return atomic_compare_exchange_strong(&ddtrace_warn_legacy_api, &expected, 0) &&
           get_dd_trace_warn_legacy_dd_trace();
}

static PHP_FUNCTION(dd_trace) {
    zval *function = NULL;
    zval *class_name = NULL;
    zval *callable = NULL;
    zval *config_array = NULL;

    if (DDTRACE_G(disable) || DDTRACE_G(disable_in_current_request)) {
        RETURN_BOOL(0);
    }

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "zzO", &class_name, &function, &callable,
                                 zend_ce_closure) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "zO", &function, &callable,
                                 zend_ce_closure) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "zza", &class_name, &function,
                                 &config_array) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "za", &function, &config_array) != SUCCESS) {
        ddtrace_log_debug(
            "Unexpected parameter combination, expected (class, function, closure | config_array) or (function, "
            "closure | config_array)");

        RETURN_BOOL(0);
    }

    if (ddtrace_should_warn_legacy()) {
        char *message =
            "dd_trace DEPRECATION NOTICE: the function `dd_trace` (target: %s%s%s) is deprecated and has become a "
            "no-op since 0.48.0, and will eventually be removed. Please follow "
            "https://github.com/DataDog/dd-trace-php/issues/924 for instructions to update your code; set "
            "DD_TRACE_WARN_LEGACY_DD_TRACE=0 to suppress this warning.";
        ddtrace_log_errf(message, class_name ? Z_STRVAL_P(class_name) : "", class_name ? "::" : "",
                         Z_STRVAL_P(function));
    }

    RETURN_FALSE;
}

static PHP_FUNCTION(trace_method) {
    zval *class_name = NULL;
    zval *function = NULL;
    zval *tracing_closure = NULL;
    zval *config_array = NULL;
    uint32_t options = 0;

    if (DDTRACE_G(disable) || DDTRACE_G(disable_in_current_request)) {
        RETURN_BOOL(0);
    }

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "zzO", &class_name, &function,
                                 &tracing_closure, zend_ce_closure) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "zza", &class_name, &function,
                                 &config_array) != SUCCESS) {
        ddtrace_log_debug("Unexpected parameters, expected (class_name, method_name, tracing_closure | config_array)");
        RETURN_BOOL(0);
    }

    if (Z_TYPE_P(class_name) != IS_STRING || Z_TYPE_P(function) != IS_STRING) {
        ddtrace_log_debug("class_name and method_name must be a string");
        RETURN_BOOL(0);
    }

    if (config_array) {
        if (_parse_config_array(config_array, &tracing_closure, &options) == FALSE) {
            RETURN_BOOL(0);
        }
    } else {
        options |= DDTRACE_DISPATCH_POSTHOOK;
    }

    zend_bool rv = ddtrace_trace(class_name, function, tracing_closure, options);
    RETURN_BOOL(rv);
}

/*
 * In PHP 7 we don't bind $this as we want only public access.
 * In PHP 5 we have to bind $this; see PHP5's hook_method for details.
 */
static PHP_FUNCTION(hook_method) {
    zend_string *class_name = NULL, *method_name = NULL;
    zval *prehook = NULL, *posthook = NULL;

    ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 2, 4)
    // clang-format off
        Z_PARAM_STR(class_name)
        Z_PARAM_STR(method_name)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_EX(prehook, zend_ce_closure, 1, 0)
        Z_PARAM_OBJECT_OF_CLASS_EX(posthook, zend_ce_closure, 1, 0)
    // clang-format on
    ZEND_PARSE_PARAMETERS_END_EX({
        ddtrace_log_debug(
            "Unable to parse parameters for DDTrace\\hook_method; expected "
            "(string $class_name, string $method_name, ?Closure $prehook = NULL, ?Closure $posthook = NULL)");
    });

    if (prehook && posthook) {
        // both callbacks given; not yet supported
        ddtrace_log_debug(
            "DDTrace\\hook_method was given both prehook and posthook. This is not yet supported; ignoring call.");
        RETURN_FALSE;
    }

    if (!prehook && !posthook) {
        ddtrace_log_debug("DDTrace\\hook_method was given neither prehook nor posthook.");
        RETURN_FALSE;
    }

    // at this point we know we have a posthook XOR posthook
    zval *callable = prehook ?: posthook;
    uint32_t options = (prehook ? DDTRACE_DISPATCH_PREHOOK : DDTRACE_DISPATCH_POSTHOOK) | DDTRACE_DISPATCH_NON_TRACING;

    // massage zend_string * into zval
    zval class_name_zv, method_name_zv;
    ZVAL_STR(&class_name_zv, class_name);
    ZVAL_STR(&method_name_zv, method_name);

    RETURN_BOOL(ddtrace_trace(&class_name_zv, &method_name_zv, callable, options));
}

static PHP_FUNCTION(hook_function) {
    zend_string *function_name = NULL;
    zval *prehook = NULL, *posthook = NULL;

    ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 1, 3)
    // clang-format off
        Z_PARAM_STR(function_name)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_EX(prehook, zend_ce_closure, 1, 0)
        Z_PARAM_OBJECT_OF_CLASS_EX(posthook, zend_ce_closure, 1, 0)
    // clang-format on
    ZEND_PARSE_PARAMETERS_END_EX({
        ddtrace_log_debug(
            "Unable to parse parameters for DDTrace\\hook_function; expected "
            "(string $function_name, ?Closure $prehook = NULL, ?Closure $posthook = NULL)");
    });

    if (prehook && posthook) {
        // both callbacks given; not yet supported
        ddtrace_log_debug(
            "DDTrace\\hook_function was given both prehook and posthook. This is not yet supported; ignoring call.");
        RETURN_FALSE;
    }

    if (!prehook && !posthook) {
        ddtrace_log_debug("DDTrace\\hook_function was given neither prehook nor posthook.");
        RETURN_FALSE;
    }

    // at this point we know we have a posthook XOR posthook
    zval *callable = prehook ?: posthook;
    uint32_t options = (prehook ? DDTRACE_DISPATCH_PREHOOK : DDTRACE_DISPATCH_POSTHOOK) | DDTRACE_DISPATCH_NON_TRACING;

    // massage zend_string * into zval
    zval function_name_zv;
    ZVAL_STR(&function_name_zv, function_name);

    RETURN_BOOL(ddtrace_trace(NULL, &function_name_zv, callable, options));
}

static PHP_FUNCTION(additional_trace_meta) {
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "")) {
        ddtrace_log_debug("Unexpected parameters to DDTrace\\additional_trace_meta");
        array_init(return_value);
        return;
    }

    ZVAL_COPY_VALUE(return_value, &DDTRACE_G(additional_trace_meta));
    zval_copy_ctor(return_value);
}

/* {{{ proto string DDTrace\add_global_tag(string $key, string $value) */
static PHP_FUNCTION(add_global_tag) {
    UNUSED(execute_data);

    zend_string *key, *val;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "SS", &key, &val) == FAILURE) {
        ddtrace_log_debug(
            "Unable to parse parameters for DDTrace\\add_global_tag; expected (string $key, string $value)");
        RETURN_NULL();
    }

    zval value_zv;
    ZVAL_STR_COPY(&value_zv, val);
    zend_hash_update(DDTRACE_G(additional_global_tags), key, &value_zv);

    RETURN_NULL();
}

static PHP_FUNCTION(trace_function) {
    zval *function = NULL;
    zval *tracing_closure = NULL;
    zval *config_array = NULL;
    uint32_t options = 0;

    if (DDTRACE_G(disable) || DDTRACE_G(disable_in_current_request)) {
        RETURN_BOOL(0);
    }

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "zO", &function, &tracing_closure,
                                 zend_ce_closure) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "za", &function, &config_array) != SUCCESS) {
        ddtrace_log_debug("Unexpected parameters, expected (function_name, tracing_closure | config_array)");
        RETURN_BOOL(0);
    }

    if (Z_TYPE_P(function) != IS_STRING) {
        ddtrace_log_debug("function_name must be a string");
        RETURN_BOOL(0);
    }

    if (config_array) {
        if (_parse_config_array(config_array, &tracing_closure, &options) == FALSE) {
            RETURN_BOOL(0);
        }
    } else {
        options |= DDTRACE_DISPATCH_POSTHOOK;
    }

    zend_bool rv = ddtrace_trace(NULL, function, tracing_closure, options);
    RETURN_BOOL(rv);
}

static PHP_FUNCTION(dd_trace_serialize_closed_spans) {
    UNUSED(execute_data);
    ddtrace_serialize_closed_spans(return_value);
}

// Invoke the function/method from the original context
static PHP_FUNCTION(dd_trace_forward_call) {
    UNUSED(execute_data);
    RETURN_FALSE;
}

static PHP_FUNCTION(dd_trace_env_config) {
    UNUSED(execute_data);
    zval *env_name = NULL;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &env_name) != SUCCESS) {
        ddtrace_log_debug("unexpected parameter. the environment variable name must be provided");
        RETURN_FALSE;
    }
    if (env_name) {
        ddtrace_php_get_configuration(return_value, env_name);
        return;
    } else {
        RETURN_NULL();
    }
}

// This function allows untracing a function.
static PHP_FUNCTION(dd_untrace) {
    UNUSED(execute_data);

    if (DDTRACE_G(disable) && DDTRACE_G(disable_in_current_request)) {
        RETURN_BOOL(0);
    }

    zval *function = NULL;

    // Remove the traced function from the global lookup
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "z", &function) != SUCCESS) {
        ddtrace_log_debug("unexpected parameter. the function name must be provided");
        RETURN_BOOL(0);
    }

    // Remove the traced function from the global lookup
    if (!function || Z_TYPE_P(function) != IS_STRING) {
        RETURN_BOOL(0);
    }

    if (DDTRACE_G(function_lookup)) {
        zend_hash_del(DDTRACE_G(function_lookup), Z_STR_P(function));
    }

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_trace_disable_in_request) {
    UNUSED(execute_data);

    DDTRACE_G(disable_in_current_request) = 1;

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_trace_reset) {
    UNUSED(execute_data);
    ddtrace_log_debug("Cannot reset traced functions on PHP 8+");
    RETURN_BOOL(0);
}

/* {{{ proto string dd_trace_serialize_msgpack(array trace_array) */
static PHP_FUNCTION(dd_trace_serialize_msgpack) {
    UNUSED(execute_data);

    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

    zval *trace_array;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "a", &trace_array) == FAILURE) {
        ddtrace_log_debug("Expected argument to dd_trace_serialize_msgpack() to be an array");
        RETURN_BOOL(0);
    }

    if (ddtrace_serialize_simple_array(trace_array, return_value) != 1) {
        RETURN_BOOL(0);
    }
} /* }}} */

// method used to be able to easily breakpoint the execution at specific PHP line in GDB
static PHP_FUNCTION(dd_trace_noop) {
    UNUSED(execute_data);

    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

    RETURN_BOOL(1);
}

/* {{{ proto int dd_trace_dd_get_memory_limit() */
static PHP_FUNCTION(dd_trace_dd_get_memory_limit) {
    UNUSED(execute_data);

    RETURN_LONG(ddtrace_get_memory_limit());
}

/* {{{ proto bool dd_trace_check_memory_under_limit() */
static PHP_FUNCTION(dd_trace_check_memory_under_limit) {
    UNUSED(execute_data);
    RETURN_BOOL(ddtrace_check_memory_under_limit() == TRUE ? 1 : 0);
}

static PHP_FUNCTION(dd_tracer_circuit_breaker_register_error) {
    UNUSED(execute_data);

    dd_tracer_circuit_breaker_register_error();

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_tracer_circuit_breaker_register_success) {
    UNUSED(execute_data);

    dd_tracer_circuit_breaker_register_success();

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_tracer_circuit_breaker_can_try) {
    UNUSED(execute_data);

    RETURN_BOOL(dd_tracer_circuit_breaker_can_try());
}

static PHP_FUNCTION(dd_tracer_circuit_breaker_info) {
    UNUSED(execute_data);

    array_init_size(return_value, 5);

    add_assoc_bool(return_value, "closed", dd_tracer_circuit_breaker_is_closed());
    add_assoc_long(return_value, "total_failures", dd_tracer_circuit_breaker_total_failures());
    add_assoc_long(return_value, "consecutive_failures", dd_tracer_circuit_breaker_consecutive_failures());
    add_assoc_long(return_value, "opened_timestamp", dd_tracer_circuit_breaker_opened_timestamp());
    add_assoc_long(return_value, "last_failure_timestamp", dd_tracer_circuit_breaker_last_failure_timestamp());
    return;
}

typedef zend_long ddtrace_zpplong_t;

static PHP_FUNCTION(ddtrace_config_app_name) {
    ddtrace_string default_str = {
        .ptr = NULL,
        .len = 0,
    };
    zend_string *default_zstr = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|S", &default_zstr) != SUCCESS) {
        RETURN_NULL();
    }
    if (default_zstr) {
        default_str.ptr = ZSTR_VAL(default_zstr);
        default_str.len = ZSTR_LEN(default_zstr);
    }

    ddtrace_string app_name = ddtrace_string_getenv_multi(ZEND_STRL("DD_SERVICE"), ZEND_STRL("DD_SERVICE_NAME"));
    bool should_free_app_name = app_name.ptr;
    if (!app_name.len) {
        if (should_free_app_name) {
            efree(app_name.ptr);
        }
        if (!default_str.len) {
            RETURN_NULL();
        }
        should_free_app_name = false;
        app_name = default_str;
    }

    ddtrace_string trimmed = ddtrace_trim(app_name);
    // Re-use and addref the default_zstr iff they match and trim didn't occur; copy otherwise
    if (default_zstr && trimmed.ptr == ZSTR_VAL(default_zstr) && trimmed.len == ZSTR_LEN(default_zstr)) {
        RETVAL_STR_COPY(default_zstr);
    } else {
        RETVAL_STRINGL(trimmed.ptr, trimmed.len);
    }
    if (should_free_app_name) {
        efree(app_name.ptr);
    }
}

static PHP_FUNCTION(ddtrace_config_distributed_tracing_enabled) {
    UNUSED(execute_data);
    RETURN_BOOL(ddtrace_config_distributed_tracing_enabled());
}

static PHP_FUNCTION(ddtrace_config_trace_enabled) {
    UNUSED(execute_data);
    RETURN_BOOL(ddtrace_config_trace_enabled());
}

static PHP_FUNCTION(ddtrace_config_integration_enabled) {
    if (!ddtrace_config_trace_enabled()) {
        RETURN_FALSE;
    }
    ddtrace_string integration;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &integration.ptr, &integration.len) != SUCCESS) {
        RETURN_NULL();
    }
    RETVAL_BOOL(ddtrace_config_integration_enabled(integration));
}

static PHP_FUNCTION(integration_analytics_enabled) {
    ddtrace_string integration;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &integration.ptr, &integration.len) != SUCCESS) {
        RETURN_NULL();
    }
    RETVAL_BOOL(ddtrace_config_integration_analytics_enabled(integration));
}

static PHP_FUNCTION(integration_analytics_sample_rate) {
    ddtrace_string integration;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &integration.ptr, &integration.len) != SUCCESS) {
        RETURN_NULL();
    }
    RETVAL_DOUBLE(ddtrace_config_integration_analytics_sample_rate(integration));
}

/* This is only exposed to serialize the container ID into an HTTP Agent header for the userland transport
 * (`DDTrace\Transport\Http`). The background sender (extension-level transport) is decoupled from userland
 * code to create any HTTP Agent headers. Once the dependency on the userland transport has been removed,
 * this function can also be removed.
 */
static PHP_FUNCTION(container_id) {
    UNUSED(execute_data);
    char *id = ddshared_container_id();
    if (id != NULL && id[0] != '\0') {
        RETVAL_STRING(id);
    } else {
        RETURN_NULL();
    }
}

static PHP_FUNCTION(trigger_error) {
    ddtrace_string message;
    ddtrace_zpplong_t error_type;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "sl", &message.ptr, &message.len, &error_type) != SUCCESS) {
        RETURN_NULL();
    }

    int level = (int)error_type;
    switch (level) {
        case E_ERROR:
        case E_WARNING:
        case E_PARSE:
        case E_NOTICE:
        case E_CORE_ERROR:
        case E_CORE_WARNING:
        case E_COMPILE_ERROR:
        case E_USER_ERROR:
        case E_USER_WARNING:
        case E_USER_NOTICE:
        case E_STRICT:
        case E_RECOVERABLE_ERROR:
        case E_DEPRECATED:
        case E_USER_DEPRECATED:
            zend_error(level, "%s", message.ptr);
            break;

        default:
            ddtrace_log_debugf("Invalid error type specified: %i", level);
            break;
    }
}

static PHP_FUNCTION(ddtrace_init) {
    if (DDTRACE_G(request_init_hook_loaded) == 1) {
        RETURN_FALSE;
    }

    ddtrace_string dir;
    int ret = 0;
    DDTRACE_G(request_init_hook_loaded) = 1;
    if (ddtrace_config_trace_enabled() && zend_parse_parameters(ZEND_NUM_ARGS(), "s", &dir.ptr, &dir.len) == SUCCESS) {
        char *init_file = emalloc(dir.len + sizeof("/dd_init.php"));
        sprintf(init_file, "%s/dd_init.php", dir.ptr);
        ret = dd_execute_php_file(init_file);
        efree(init_file);
    }

    if (DDTRACE_G(auto_prepend_file) && DDTRACE_G(auto_prepend_file)[0]) {
        dd_execute_auto_prepend_file(DDTRACE_G(auto_prepend_file));
    }
    RETVAL_BOOL(ret);
}

static PHP_FUNCTION(dd_trace_send_traces_via_thread) {
    char *payload = NULL;
    ddtrace_zpplong_t num_traces = 0;
    ddtrace_zppstrlen_t payload_len = 0;
    zval *curl_headers = NULL;

    // Agent HTTP headers are now set at the extension level so 'curl_headers' from userland is ignored
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "las", &num_traces, &curl_headers, &payload,
                                 &payload_len) == FAILURE) {
        ddtrace_log_debug("dd_trace_send_traces_via_thread() expects trace count, http headers, and http body");
        RETURN_FALSE;
    }

    bool result = ddtrace_send_traces_via_thread(num_traces, payload, payload_len);
    dd_prepare_for_new_trace();
    RETURN_BOOL(result);
}

static PHP_FUNCTION(dd_trace_buffer_span) {
    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }
    zval *trace_array = NULL;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "a", &trace_array) == FAILURE) {
        ddtrace_log_debug("Expected group id and an array");
        RETURN_BOOL(0);
    }

    char *data;
    size_t size;
    if (ddtrace_serialize_simple_array_into_c_string(trace_array, &data, &size)) {
        RETVAL_BOOL(ddtrace_coms_buffer_data(DDTRACE_G(traces_group_id), data, size));

        free(data);
        return;
    } else {
        RETURN_FALSE;
    }
}

static PHP_FUNCTION(dd_trace_coms_trigger_writer_flush) {
    UNUSED(execute_data);

    RETURN_LONG(ddtrace_coms_trigger_writer_flush());
}

#define FUNCTION_NAME_MATCHES(function) ((sizeof(function) - 1) == fn_len && strncmp(fn, function, fn_len) == 0)

static PHP_FUNCTION(dd_trace_internal_fn) {
    UNUSED(execute_data);
    zval ***params = NULL;
    uint32_t params_count = 0;

    zval *function_val = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "z*", &function_val, &params, &params_count) != SUCCESS) {
        ddtrace_log_debug("unexpected parameter. the function name must be provided");
        RETURN_BOOL(0);
    }

    if (!function_val || Z_TYPE_P(function_val) != IS_STRING) {
        ddtrace_log_debug("unexpected parameter. the function name must be provided");
        RETURN_BOOL(0);
    }
    char *fn = Z_STRVAL_P(function_val);
    size_t fn_len = Z_STRLEN_P(function_val);
    if (fn_len == 0 && fn) {
        fn_len = strlen(fn);
    }

    RETVAL_FALSE;
    if (fn && fn_len > 0) {
        if (FUNCTION_NAME_MATCHES("ddtrace_reload_config")) {
            ddtrace_reload_config();
            RETVAL_TRUE;
        } else if (FUNCTION_NAME_MATCHES("init_and_start_writer")) {
            RETVAL_BOOL(ddtrace_coms_init_and_start_writer());
        } else if (FUNCTION_NAME_MATCHES("ddtrace_coms_next_group_id")) {
            RETVAL_LONG(ddtrace_coms_next_group_id());
        } else if (params_count == 2 && FUNCTION_NAME_MATCHES("ddtrace_coms_buffer_span")) {
            zval *group_id = ZVAL_VARARG_PARAM(params, 0);
            zval *trace_array = ZVAL_VARARG_PARAM(params, 1);
            char *data = NULL;
            size_t size = 0;
            if (ddtrace_serialize_simple_array_into_c_string(trace_array, &data, &size)) {
                RETVAL_BOOL(ddtrace_coms_buffer_data(Z_LVAL_P(group_id), data, size));
                free(data);
            } else {
                RETVAL_FALSE;
            }
        } else if (params_count == 2 && FUNCTION_NAME_MATCHES("ddtrace_coms_buffer_data")) {
            zval *group_id = ZVAL_VARARG_PARAM(params, 0);
            zval *data = ZVAL_VARARG_PARAM(params, 1);
            RETVAL_BOOL(ddtrace_coms_buffer_data(Z_LVAL_P(group_id), Z_STRVAL_P(data), Z_STRLEN_P(data)));
        } else if (FUNCTION_NAME_MATCHES("shutdown_writer")) {
            RETVAL_BOOL(ddtrace_coms_flush_shutdown_writer_synchronous());
        } else if (params_count == 1 && FUNCTION_NAME_MATCHES("set_writer_send_on_flush")) {
            RETVAL_BOOL(ddtrace_coms_set_writer_send_on_flush(IS_TRUE_P(ZVAL_VARARG_PARAM(params, 0))));
        } else if (FUNCTION_NAME_MATCHES("test_consumer")) {
            ddtrace_coms_test_consumer();
            RETVAL_TRUE;
        } else if (FUNCTION_NAME_MATCHES("test_writers")) {
            ddtrace_coms_test_writers();
            RETVAL_TRUE;
        } else if (FUNCTION_NAME_MATCHES("test_msgpack_consumer")) {
            ddtrace_coms_test_msgpack_consumer();
            RETVAL_TRUE;
        } else if (FUNCTION_NAME_MATCHES("synchronous_flush")) {
            uint32_t timeout = 100;
            if (params_count == 1) {
                timeout = Z_LVAL_P(ZVAL_VARARG_PARAM(params, 0));
            }
            ddtrace_coms_synchronous_flush(timeout);
            RETVAL_TRUE;
        }
    }
}

/* {{{ proto string dd_trace_set_trace_id() */
static PHP_FUNCTION(dd_trace_set_trace_id) {
    UNUSED(execute_data);

    zval *trace_id = NULL;
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "|z!", &trace_id) == SUCCESS) {
        if (ddtrace_set_userland_trace_id(trace_id) == TRUE) {
            RETURN_BOOL(1);
        }
    }

    RETURN_BOOL(0);
}

/* {{{ proto string dd_trace_push_span_id() */
static PHP_FUNCTION(dd_trace_push_span_id) {
    UNUSED(execute_data);

    zval *existing_id = NULL;
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "|z!", &existing_id) == SUCCESS) {
        if (ddtrace_push_userland_span_id(existing_id) == TRUE) {
            RETURN_STR(ddtrace_span_id_as_string(ddtrace_peek_span_id()));
        }
    }

    RETURN_STR(ddtrace_span_id_as_string(ddtrace_push_span_id(0)));
}

/* {{{ proto string dd_trace_pop_span_id() */
static PHP_FUNCTION(dd_trace_pop_span_id) {
    UNUSED(execute_data);
    uint64_t id = ddtrace_pop_span_id();

    if (DDTRACE_G(span_ids_top) == NULL && get_dd_trace_auto_flush_enabled()) {
        if (ddtrace_flush_tracer() == FAILURE) {
            ddtrace_log_debug("Unable to auto flush the tracer");
        }
    }

    RETURN_STR(ddtrace_span_id_as_string(id));
}

/* {{{ proto string dd_trace_peek_span_id() */
static PHP_FUNCTION(dd_trace_peek_span_id) {
    UNUSED(execute_data);
    RETURN_STR(ddtrace_span_id_as_string(ddtrace_peek_span_id()));
}

/* {{{ proto string DDTrace\active_span() */
static PHP_FUNCTION(active_span) {
    UNUSED(execute_data);
    if (!DDTRACE_G(open_spans_top)) {
        if (get_dd_trace_generate_root_span()) {
            ddtrace_push_root_span();  // ensure root span always exists, especially after serialization for testing
        } else {
            RETURN_NULL();
        }
    }
    RETURN_OBJ_COPY(&DDTRACE_G(open_spans_top)->span.std);
}

/* {{{ proto string DDTrace\start_span() */
static PHP_FUNCTION(start_span) {
    double start_time_seconds = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|d", &start_time_seconds) != SUCCESS) {
        ddtrace_log_debug("unexpected parameter. expecting double for start time");
        RETURN_FALSE;
    }

    ddtrace_span_fci *span_fci = ddtrace_init_span();
    ddtrace_open_span(span_fci);

    if (start_time_seconds > 0) {
        span_fci->span.start = (uint64_t)(start_time_seconds * 1000000000);
    }

    RETURN_OBJ_COPY(&span_fci->span.std);
}

/* {{{ proto string DDTrace\close_span() */
static PHP_FUNCTION(close_span) {
    double finish_time_seconds = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|d", &finish_time_seconds) != SUCCESS) {
        ddtrace_log_debug("unexpected parameter. expecting double for finish time");
        RETURN_FALSE;
    }

    if (!DDTRACE_G(open_spans_top) || DDTRACE_G(open_spans_top)->execute_data ||
        (get_dd_trace_generate_root_span() && DDTRACE_G(open_spans_top)->next == NULL)) {
        ddtrace_log_err("There is no user-span on the top of the stack. Cannot close.");
        RETURN_NULL();
    }

    // we do not expose the monotonic time here, so do not use it as reference time to calculate difference
    uint64_t start_time = DDTRACE_G(open_spans_top)->span.start;
    uint64_t finish_time = (uint64_t)(finish_time_seconds * 1000000000);
    if (finish_time < start_time) {
        dd_trace_stop_span_time(&DDTRACE_G(open_spans_top)->span);
    } else {
        DDTRACE_G(open_spans_top)->span.duration = finish_time - start_time;
    }

    ddtrace_close_span(DDTRACE_G(open_spans_top));
    RETURN_NULL();
}

static PHP_FUNCTION(flush) {
    UNUSED(execute_data);
    if (ddtrace_flush_tracer() == FAILURE) {
        ddtrace_log_debug("Unable to flush the tracer");
    }
    RETURN_NULL();
}

/* {{{ proto string \DDTrace\trace_id() */
static PHP_FUNCTION(trace_id) {
    UNUSED(execute_data);
    RETURN_STR(ddtrace_span_id_as_string(DDTRACE_G(trace_id)));
}

/* {{{ proto array \DDTrace\current_context() */
static PHP_FUNCTION(current_context) {
    UNUSED(execute_data);

    size_t length;
    char buf[DD_TRACE_MAX_ID_LEN + 1];

    array_init(return_value);

    // Add Trace ID
    length = snprintf(buf, sizeof(buf), "%" PRIu64, DDTRACE_G(trace_id));
    add_assoc_stringl_ex(return_value, "trace_id", sizeof("trace_id") - 1, buf, length);

    // Add Span ID
    length = snprintf(buf, sizeof(buf), "%" PRIu64, ddtrace_peek_span_id());
    add_assoc_stringl_ex(return_value, "span_id", sizeof("span_id") - 1, buf, length);

    // Add Version
    char *version = get_dd_version();
    length = strlen(version);
    if (length > 0) {
        add_assoc_stringl_ex(return_value, "version", sizeof("version") - 1, version, length);
    } else {
        add_assoc_null_ex(return_value, "version", sizeof("version") - 1);
    }
    free(version);

    // Add Env
    char *env = get_dd_env();
    length = strlen(env);
    if (length > 0) {
        add_assoc_stringl_ex(return_value, "env", sizeof("env") - 1, env, length);
    } else {
        add_assoc_null_ex(return_value, "env", sizeof("env") - 1);
    }
    free(env);
}

/* {{{ proto string dd_trace_closed_spans_count() */
static PHP_FUNCTION(dd_trace_closed_spans_count) {
    UNUSED(execute_data);
    RETURN_LONG(DDTRACE_G(closed_spans_count));
}

BOOL_T ddtrace_tracer_is_limited(void) {
    int64_t limit = get_dd_trace_spans_limit();
    if (limit >= 0) {
        int64_t open_spans = DDTRACE_G(open_spans_count);
        int64_t closed_spans = DDTRACE_G(closed_spans_count);
        if ((open_spans + closed_spans) >= limit) {
            return TRUE;
        }
    }
    return ddtrace_check_memory_under_limit() == TRUE ? FALSE : TRUE;
}

/* {{{ proto string dd_trace_tracer_is_limited() */
static PHP_FUNCTION(dd_trace_tracer_is_limited) {
    UNUSED(execute_data);
    RETURN_BOOL(ddtrace_tracer_is_limited() == TRUE ? 1 : 0);
}

/* {{{ proto string dd_trace_compile_time_microseconds() */
static PHP_FUNCTION(dd_trace_compile_time_microseconds) {
    UNUSED(execute_data);
    RETURN_LONG(ddtrace_compile_time_get());
}

static PHP_FUNCTION(startup_logs) {
    UNUSED(execute_data);

    smart_str buf = {0};
    ddtrace_startup_logging_json(&buf);
    ZVAL_NEW_STR(return_value, buf.s);
}

static const zend_function_entry ddtrace_functions[] = {
    DDTRACE_FE(dd_trace, arginfo_dd_trace),  // Noop legacy API
    DDTRACE_FE(dd_trace_buffer_span, arginfo_dd_trace_buffer_span),
    DDTRACE_FE(dd_trace_check_memory_under_limit, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_closed_spans_count, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_coms_trigger_writer_flush, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_dd_get_memory_limit, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_disable_in_request, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_env_config, arginfo_dd_trace_env_config),
    DDTRACE_FE(dd_trace_forward_call, arginfo_ddtrace_void),  // Noop legacy API
    DDTRACE_FALIAS(dd_trace_generate_id, dd_trace_push_span_id, arginfo_dd_trace_push_span_id),
    DDTRACE_FE(dd_trace_internal_fn, arginfo_dd_trace_internal_fn),
    DDTRACE_FE(dd_trace_noop, arginfo_ddtrace_void),
    DDTRACE_NS_FE(flush, arginfo_ddtrace_void),
    DDTRACE_NS_FE(start_span, arginfo_dd_trace_start_span),
    DDTRACE_NS_FE(close_span, arginfo_dd_trace_close_span),
    DDTRACE_NS_FE(active_span, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_peek_span_id, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_pop_span_id, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_push_span_id, arginfo_dd_trace_push_span_id),
    DDTRACE_NS_FE(trace_id, arginfo_ddtrace_void),
    DDTRACE_NS_FE(current_context, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_reset, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_send_traces_via_thread, arginfo_dd_trace_send_traces_via_thread),
    DDTRACE_FE(dd_trace_serialize_closed_spans, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_serialize_msgpack, arginfo_dd_trace_serialize_msgpack),
    DDTRACE_FE(dd_trace_set_trace_id, arginfo_dd_trace_set_trace_id),
    DDTRACE_FE(dd_trace_tracer_is_limited, arginfo_ddtrace_void),
    DDTRACE_FE(dd_tracer_circuit_breaker_can_try, arginfo_ddtrace_void),
    DDTRACE_FE(dd_tracer_circuit_breaker_info, arginfo_ddtrace_void),
    DDTRACE_FE(dd_tracer_circuit_breaker_register_error, arginfo_ddtrace_void),
    DDTRACE_FE(dd_tracer_circuit_breaker_register_success, arginfo_ddtrace_void),
    DDTRACE_FE(dd_untrace, arginfo_dd_untrace),
    DDTRACE_FE(dd_trace_compile_time_microseconds, arginfo_ddtrace_void),
    DDTRACE_FE(ddtrace_config_app_name, arginfo_ddtrace_config_app_name),
    DDTRACE_FE(ddtrace_config_distributed_tracing_enabled, arginfo_ddtrace_void),
    DDTRACE_FE(ddtrace_config_integration_enabled, arginfo_ddtrace_config_integration_enabled),
    DDTRACE_FE(ddtrace_config_trace_enabled, arginfo_ddtrace_void),
    DDTRACE_FE(ddtrace_init, arginfo_ddtrace_init),
    DDTRACE_NS_FE(add_global_tag, arginfo_ddtrace_add_global_tag),
    DDTRACE_NS_FE(additional_trace_meta, arginfo_ddtrace_void),
    DDTRACE_NS_FE(trace_function, arginfo_ddtrace_trace_function),
    DDTRACE_FALIAS(dd_trace_function, trace_function, arginfo_ddtrace_trace_function),
    DDTRACE_NS_FE(trace_method, arginfo_ddtrace_trace_method),
    DDTRACE_FALIAS(dd_trace_method, trace_method, arginfo_ddtrace_trace_method),
    DDTRACE_NS_FE(hook_function, arginfo_ddtrace_hook_function),
    DDTRACE_NS_FE(hook_method, arginfo_ddtrace_hook_method),
    DDTRACE_NS_FE(startup_logs, arginfo_ddtrace_void),
    DDTRACE_SUB_NS_FE("Config\\", integration_analytics_enabled, arginfo_ddtrace_config_integration_analytics_enabled),
    DDTRACE_SUB_NS_FE("Config\\", integration_analytics_sample_rate,
                      arginfo_ddtrace_config_integration_analytics_sample_rate),
    DDTRACE_SUB_NS_FE("System\\", container_id, arginfo_ddtrace_void),
    DDTRACE_SUB_NS_FE("Testing\\", trigger_error, arginfo_ddtrace_testing_trigger_error),
    DDTRACE_FE_END};

zend_module_entry ddtrace_module_entry = {STANDARD_MODULE_HEADER,
                                          PHP_DDTRACE_EXTNAME,
                                          ddtrace_functions,
                                          PHP_MINIT(ddtrace),
                                          PHP_MSHUTDOWN(ddtrace),
                                          PHP_RINIT(ddtrace),
                                          PHP_RSHUTDOWN(ddtrace),
                                          PHP_MINFO(ddtrace),
                                          PHP_DDTRACE_VERSION,
                                          PHP_MODULE_GLOBALS(ddtrace),
                                          PHP_GINIT(ddtrace),
                                          NULL,
                                          NULL,
                                          STANDARD_MODULE_PROPERTIES_EX};

#ifdef COMPILE_DL_DDTRACE
ZEND_GET_MODULE(ddtrace)
#if defined(ZTS) && PHP_VERSION_ID >= 70000
ZEND_TSRMLS_CACHE_DEFINE();
#endif
#endif

// the following operations are performed in order to put the tracer in a state when a new trace can be started:
//   - set a new trace (group) id
void dd_prepare_for_new_trace(void) { DDTRACE_G(traces_group_id) = ddtrace_coms_next_group_id(); }

void dd_read_distributed_tracing_ids(void) {
    zend_string *trace_id_str, *parent_id_str;
    BOOL_T success = true;

    if (zai_read_header_literal("X_DATADOG_TRACE_ID", &trace_id_str) == ZAI_HEADER_SUCCESS) {
        if (ZSTR_LEN(trace_id_str) != 1 || ZSTR_VAL(trace_id_str)[0] != '0') {
            zval trace_zv;
            ZVAL_STR(&trace_zv, trace_id_str);
            success = ddtrace_set_userland_trace_id(&trace_zv);
        }
    }

    if (success && zai_read_header_literal("X_DATADOG_PARENT_ID", &parent_id_str) == ZAI_HEADER_SUCCESS) {
        zval parent_zv;
        ZVAL_STR(&parent_zv, parent_id_str);
        if (ZSTR_LEN(parent_id_str) != 1 || ZSTR_VAL(parent_id_str)[0] != '0') {
            if (ddtrace_push_userland_span_id(&parent_zv)) {
                DDTRACE_G(distributed_parent_trace_id) = DDTRACE_G(span_ids_top)->id;
            } else {
                DDTRACE_G(trace_id) = 0;
            }
        }
    }

    zai_read_header_literal("X_DATADOG_ORIGIN", &DDTRACE_G(dd_origin));
}
