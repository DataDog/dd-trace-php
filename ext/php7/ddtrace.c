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
#include <components/sapi/sapi.h>
#include <headers/headers.h>
#include <hook/hook.h>
#include <inttypes.h>
#include <php.h>
#include <php_ini.h>
#include <php_main.h>
#include <pthread.h>
#include <stdatomic.h>

#include <ext/spl/spl_exceptions.h>
#include <ext/standard/info.h>
#include <ext/standard/php_string.h>

#include "arrays.h"
#include "auto_flush.h"
#include "circuit_breaker.h"
#include "comms_php.h"
#include "compat_string.h"
#include "compatibility.h"
#include "coms.h"
#include "config/config.h"
#include "configuration.h"
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
#include "priority_sampling/priority_sampling.h"
#include "random.h"
#include "request_hooks.h"
#include "serializer.h"
#include "signals.h"
#include "span.h"
#include "startup_logging.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"

#include <zai/hook/uhook.h>

bool ddtrace_has_excluded_module;
static zend_module_entry *ddtrace_module;

atomic_int ddtrace_warn_legacy_api;

ZEND_DECLARE_MODULE_GLOBALS(ddtrace)

#ifdef COMPILE_DL_DDTRACE
ZEND_GET_MODULE(ddtrace)
#if defined(ZTS) && PHP_VERSION_ID >= 70000
ZEND_TSRMLS_CACHE_DEFINE();
#endif
#endif

PHP_INI_BEGIN()
STD_PHP_INI_BOOLEAN("ddtrace.disable", "0", PHP_INI_SYSTEM, OnUpdateBool, disable, zend_ddtrace_globals,
                    ddtrace_globals)

// Exposed for testing only
STD_PHP_INI_ENTRY("ddtrace.cgroup_file", "/proc/self/cgroup", PHP_INI_SYSTEM, OnUpdateString, cgroup_file,
                  zend_ddtrace_globals, ddtrace_globals)
PHP_INI_END()

#if PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400
static void ddtrace_sort_modules(void *base, size_t count, size_t siz, compare_func_t compare, swap_func_t swp) {
    UNUSED(siz);
    UNUSED(compare);
    UNUSED(swp);

    // swap ddtrace and opcache for the rest of the modules lifecycle, so that opcache is always executed after ddtrace
    for (Bucket *module = base, *end = module + count, *ddtrace_module = NULL; module < end; ++module) {
        zend_module_entry *m = (zend_module_entry *)Z_PTR(module->val);
        if (m->name == ddtrace_module_entry.name) {
            ddtrace_module = module;
        }
        if (ddtrace_module && strcmp(m->name, "Zend OPcache") == 0) {
            Bucket tmp = *ddtrace_module;
            *ddtrace_module = *module;
            *module = tmp;
            break;
        }
    }
}
#endif

static int ddtrace_startup(zend_extension *extension) {
#if PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400
    // Turns out with zai config we have dynamically allocated INI entries. This does not play well with PHP 7.3
    // As of PHP 7.3 opcache stores INI entry values in SHM. However, only as of PHP 7.4 opcache delays detaching SHM.
    // In PHP 7.3 SHM is freed in MSHUTDOWN, which may be executed before our extension, if we do not force an order.
    // We have to sort this manually here, as opcache only registers itself as extension during zend_extension.startup.
    zend_hash_sort_ex(&module_registry, ddtrace_sort_modules, NULL, 0);
#endif

    ddtrace_resource = zend_get_resource_handle(extension);
#if PHP_VERSION_ID >= 70400
    ddtrace_op_array_extension = zend_get_op_array_extension_handle();
#endif

    ddtrace_excluded_modules_startup();
    // We deliberately leave handler replacement during startup, even though this uses some config
    // This touches global state, which, while unlikely, may play badly when interacting with other extensions, if done
    // post-startup
    ddtrace_internal_handlers_startup(extension);
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
                                                  ddtrace_message_handler,
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

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace_set_distributed_tracing_context, 0, 0, 2)
ZEND_ARG_INFO(0, trace_id)
ZEND_ARG_INFO(0, parent_id)
ZEND_ARG_INFO(0, origin)
ZEND_ARG_INFO(0, tags)
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

ZEND_BEGIN_ARG_INFO_EX(arginfo_get_priority_sampling, 0, 0, 0)
ZEND_ARG_INFO(0, global)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_set_priority_sampling, 0, 0, 1)
ZEND_ARG_INFO(0, priority)
ZEND_ARG_INFO(0, global)
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
    array_init(ddtrace_spandata_property_meta_zval(&span_fci->span));
    array_init(ddtrace_spandata_property_metrics_zval(&span_fci->span));
    return &span_fci->span.std;
}

static void ddtrace_span_data_free_storage(zend_object *object) {
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)object;
    if (span_fci->dispatch) {
        ddtrace_dispatch_release(span_fci->dispatch);
        span_fci->dispatch = NULL;
    }
    zend_object_std_dtor(object);
    // Prevent use after free after zend_objects_store_free_object_storage is called (e.g. preloading)
    memset(object->properties_table, 0, sizeof(((ddtrace_span_t *)NULL)->properties_table_placeholder));
}

static zend_object *ddtrace_span_data_clone_obj(zval *old_zv) {
    zend_object *old_obj = Z_OBJ_P(old_zv);
    zend_object *new_obj = ddtrace_span_data_create(old_obj->ce);
    zend_objects_clone_members(new_obj, old_obj);
    return new_obj;
}

#if PHP_VERSION_ID >= 70400
static zval *ddtrace_span_data_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#else
static void ddtrace_span_data_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#endif
    if (Z_TYPE_P(member) == IS_STRING &&
        (zend_string_equals_literal(Z_STR_P(member), "parent") || zend_string_equals_literal(Z_STR_P(member), "id"))) {
        zend_throw_error(zend_ce_error, "Cannot modify readonly property %s::$%s", ZSTR_VAL(Z_OBJCE_P(object)->name),
                         Z_STRVAL_P(member));
#if PHP_VERSION_ID >= 70400
        return &EG(uninitialized_zval);
#else
        return;
#endif
    }

#if PHP_VERSION_ID >= 70400
    return zend_std_write_property(object, member, value, cache_slot);
#else
    zend_std_write_property(object, member, value, cache_slot);
#endif
}

static PHP_METHOD(DDTrace_SpanData, getDuration) {
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)Z_OBJ_P(getThis());
    RETURN_LONG(span_fci->span.duration);
}

static PHP_METHOD(DDTrace_SpanData, getStartTime) {
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)Z_OBJ_P(getThis());
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
    ddtrace_span_data_handlers.write_property = ddtrace_span_data_readonly;

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
    zend_declare_property_null(ddtrace_ce_span_data, "exception", sizeof("exception") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "parent", sizeof("parent") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "id", sizeof("id") - 1, ZEND_ACC_PUBLIC);
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
        case DATADOG_PHP_SAPI_TEA:
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

    zai_hook_minit();

    zai_uhook_minit();

    REGISTER_STRING_CONSTANT("DD_TRACE_VERSION", PHP_DDTRACE_VERSION, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("DD_TRACE_PRIORITY_SAMPLING_AUTO_KEEP", PRIORITY_SAMPLING_AUTO_KEEP,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT", PRIORITY_SAMPLING_AUTO_REJECT,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("DD_TRACE_PRIORITY_SAMPLING_USER_KEEP", PRIORITY_SAMPLING_USER_KEEP,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("DD_TRACE_PRIORITY_SAMPLING_USER_REJECT", PRIORITY_SAMPLING_USER_REJECT,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("DD_TRACE_PRIORITY_SAMPLING_UNKNOWN", DDTRACE_PRIORITY_SAMPLING_UNKNOWN,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("DD_TRACE_PRIORITY_SAMPLING_UNSET", DDTRACE_PRIORITY_SAMPLING_UNSET,
                           CONST_CS | CONST_PERSISTENT);
    REGISTER_INI_ENTRIES();

    zval *ddtrace_module_zv = zend_hash_str_find(&module_registry, ZEND_STRL("ddtrace"));
    if (ddtrace_module_zv) {
        ddtrace_module = Z_PTR_P(ddtrace_module_zv);
    }

    // config initialization needs to be at the top
    if (!ddtrace_config_minit(module_number)) {
        return FAILURE;
    }
    dd_disable_if_incompatible_sapi_detected();
    atomic_init(&ddtrace_warn_legacy_api, 1);

    /* This allows an extension (e.g. extension=ddtrace.so) to have zend_engine
     * hooks too, but not loadable as zend_extension=ddtrace.so.
     * See http://www.phpinternalsbook.com/php7/extensions_design/zend_extensions.html#hybrid-extensions
     * {{{ */
    zend_register_extension(&_dd_zend_extension_entry, ddtrace_module_entry.handle);
#ifdef COMPILE_DL_DDTRACE
    Dl_info infos;
    // The symbol used needs to be public on Alpine.
    dladdr(get_module, &infos);
    dlopen(infos.dli_fname, RTLD_LAZY);
#endif
    /* }}} */

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    ddtrace_bgs_log_minit();

    ddtrace_dogstatsd_client_minit();
    ddshared_minit();

    dd_register_span_data_ce();
    dd_register_fatal_error_ce();

    ddtrace_engine_hooks_minit();

    ddtrace_coms_minit();

    ddtrace_integrations_minit();

    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

    zai_uhook_mshutdown();

    zai_hook_mshutdown();

    UNREGISTER_INI_ENTRIES();

    /* prevent unloading ddtrace, extension shutdown is called later */
    if (ddtrace_module) {
        ddtrace_module->handle = NULL;
    }

    if (DDTRACE_G(disable) == 1) {
        zai_config_mshutdown();
        return SUCCESS;
    }

    ddtrace_integrations_mshutdown();

    ddtrace_signals_mshutdown();

    ddtrace_coms_mshutdown();
    if (ddtrace_coms_flush_shutdown_writer_synchronous()) {
        ddtrace_coms_curl_shutdown();

        ddtrace_bgs_log_mshutdown();
    }

    ddtrace_engine_hooks_mshutdown();

    zai_config_mshutdown();

    return SUCCESS;
}

static void dd_rinit_once(void) {
    /* The env vars are memoized on MINIT before the SAPI env vars are available.
     * We use the first RINIT to bust the env var cache and use the SAPI env vars.
     * TODO Audit/remove config usages before RINIT and move config init to RINIT.
     */
    ddtrace_startup_logging_first_rinit();

    // Uses config, cannot run earlier
    ddtrace_signals_first_rinit();
    ddtrace_coms_init_and_start_writer();
}

static pthread_once_t dd_rinit_config_once_control = PTHREAD_ONCE_INIT;
static pthread_once_t dd_rinit_once_control = PTHREAD_ONCE_INIT;

static void dd_initialize_request() {
    array_init_size(&DDTRACE_G(additional_trace_meta), ddtrace_num_error_tags);
    DDTRACE_G(additional_global_tags) = zend_new_array(0);
    DDTRACE_G(default_priority_sampling) = DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
    DDTRACE_G(propagated_priority_sampling) = DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
    zend_hash_init(&DDTRACE_G(root_span_tags_preset), 8, shhhht, ZVAL_PTR_DTOR, 0);
    zend_hash_init(&DDTRACE_G(propagated_root_span_tags), 8, shhhht, ZVAL_PTR_DTOR, 0);

    // Things that should only run on the first RINIT
    pthread_once(&dd_rinit_once_control, dd_rinit_once);

    if (ZSTR_LEN(get_DD_TRACE_REQUEST_INIT_HOOK())) {
        dd_request_init_hook_rinit();
    }

    ddtrace_internal_handlers_rinit();
    ddtrace_bgs_log_rinit(PG(error_log));
    ddtrace_dispatch_init();

    // This allows us to hook the ZEND_HANDLE_EXCEPTION pseudo opcode
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op));
    EG(exception_op)->opcode = ZEND_HANDLE_EXCEPTION;

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

    if (get_DD_TRACE_GENERATE_ROOT_SPAN()) {
        ddtrace_push_root_span();
    }
}

static PHP_RINIT_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

    zai_hook_rinit();

    if (ddtrace_has_excluded_module == true) {
        DDTRACE_G(disable) = 2;
    }

    // ZAI config is always set up
    pthread_once(&dd_rinit_config_once_control, ddtrace_config_first_rinit);
    zai_config_rinit();

    if (strcmp(sapi_module.name, "cli") == 0 && !get_DD_TRACE_CLI_ENABLED()) {
        DDTRACE_G(disable) = 2;
    }

    if (DDTRACE_G(disable)) {
        ddtrace_disable_tracing_in_current_request();
    }

    DDTRACE_G(request_init_hook_loaded) = 0;

    if (!get_DD_TRACE_ENABLED()) {
        return SUCCESS;
    }

    dd_initialize_request();

    return SUCCESS;
}

static void dd_clean_globals() {
    zval_dtor(&DDTRACE_G(additional_trace_meta));
    zend_array_destroy(DDTRACE_G(additional_global_tags));
    zend_hash_destroy(&DDTRACE_G(root_span_tags_preset));
    zend_hash_destroy(&DDTRACE_G(propagated_root_span_tags));
    ZVAL_NULL(&DDTRACE_G(additional_trace_meta));

    if (DDTRACE_G(dd_origin)) {
        zend_string_release(DDTRACE_G(dd_origin));
    }

    ddtrace_internal_handlers_rshutdown();
    ddtrace_dogstatsd_client_rshutdown();

    ddtrace_dispatch_destroy();
    ddtrace_free_span_stacks();
    ddtrace_coms_rshutdown();

    if (ZSTR_LEN(get_DD_TRACE_REQUEST_INIT_HOOK())) {
        dd_request_init_hook_rshutdown();
    }
}

static PHP_RSHUTDOWN_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

    zai_hook_rshutdown();

    if (!get_DD_TRACE_ENABLED()) {
        ddtrace_free_span_id_stack();
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

    dd_clean_globals();
    ddtrace_free_span_id_stack();

    return SUCCESS;
}

int ddtrace_post_deactivate(void) {
    // zai config may be accessed indirectly via other modules RSHUTDOWN, so delay this until the last possible time
    zai_config_rshutdown();
    return SUCCESS;
}

void ddtrace_disable_tracing_in_current_request(void) {
    zend_string *zero = zend_string_init("0", 1, 0);
    zend_alter_ini_entry(zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_ENABLED].ini_entries[0]->name, zero,
                         ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME);
    zend_string_release(zero);
}

bool ddtrace_alter_dd_trace_disabled_config(zval *old_value, zval *new_value) {
    if (Z_TYPE_P(old_value) == Z_TYPE_P(new_value)) {
        return true;
    }

    if (DDTRACE_G(disable)) {
        return Z_TYPE_P(new_value) == IS_FALSE;  // no changing to enabled allowed if globally disabled
    }

    if (Z_TYPE_P(old_value) == IS_FALSE) {
        dd_initialize_request();
    } else if (!DDTRACE_G(disable)) {  // if this is true, the request has not been initialized at all
        ddtrace_close_all_open_spans();
        dd_clean_globals();
    }

    return true;
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

    if (!DDTRACE_G(disable)) {
        _dd_info_diagnostics_table();
    }

    DISPLAY_INI_ENTRIES();
}

static bool _parse_config_array(zval *config_array, zval **tracing_closure, uint32_t *options) {
    if (Z_TYPE_P(config_array) != IS_ARRAY) {
        ddtrace_log_debug("Expected config_array to be an associative array");
        return false;
    }

    zval *value;
    zend_string *key;

    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(Z_ARRVAL_P(config_array), key, value) {
        if (!key) {
            ddtrace_log_debug("Expected config_array to be an associative array");
            return false;
        }
        // TODO Optimize this
        if (strcmp("posthook", ZSTR_VAL(key)) == 0) {
            if (Z_TYPE_P(value) == IS_OBJECT && instanceof_function(Z_OBJCE_P(value), zend_ce_closure)) {
                *tracing_closure = value;
                *options |= DDTRACE_DISPATCH_POSTHOOK;
            } else {
                ddtrace_log_debugf("Expected '%s' to be an instance of Closure", ZSTR_VAL(key));
                return false;
            }
        } else if (strcmp("prehook", ZSTR_VAL(key)) == 0) {
            if (Z_TYPE_P(value) == IS_OBJECT && instanceof_function(Z_OBJCE_P(value), zend_ce_closure)) {
                *tracing_closure = value;
                *options |= DDTRACE_DISPATCH_PREHOOK;
            } else {
                ddtrace_log_debugf("Expected '%s' to be an instance of Closure", ZSTR_VAL(key));
                return false;
            }
        } else if (strcmp("instrument_when_limited", ZSTR_VAL(key)) == 0) {
            if (Z_TYPE_P(value) == IS_LONG) {
                if (Z_LVAL_P(value)) {
                    *options |= DDTRACE_DISPATCH_INSTRUMENT_WHEN_LIMITED;
                }
            } else {
                ddtrace_log_debugf("Expected '%s' to be an int", ZSTR_VAL(key));
                return false;
            }
        } else {
            ddtrace_log_debugf("Unknown option '%s' in config_array", ZSTR_VAL(key));
            return false;
        }
    }
    ZEND_HASH_FOREACH_END();
    if (!*tracing_closure) {
        ddtrace_log_debug("Required key 'posthook' or 'prehook' not found in config_array");
        return false;
    }
    return true;
}

static bool ddtrace_should_warn_legacy(void) {
    int expected = 1;
    return atomic_compare_exchange_strong(&ddtrace_warn_legacy_api, &expected, 0) &&
           get_DD_TRACE_WARN_LEGACY_DD_TRACE();
}

static PHP_FUNCTION(dd_trace) {
    zval *function = NULL;
    zval *class_name = NULL;
    zval *callable = NULL;
    zval *config_array = NULL;

    if (!get_DD_TRACE_ENABLED()) {
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
        if (class_name) {
            convert_to_string(class_name);
        }
        convert_to_string(function);
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

    if (!get_DD_TRACE_ENABLED()) {
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
        if (_parse_config_array(config_array, &tracing_closure, &options) == false) {
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

    if (!get_DD_TRACE_ENABLED()) {
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

    if (!get_DD_TRACE_ENABLED()) {
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
        if (_parse_config_array(config_array, &tracing_closure, &options) == false) {
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
    zend_string *env_name;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &env_name) == FAILURE) {
        ddtrace_log_debug("Unable to parse parameters for dd_trace_env_config; expected (string $env_name)");
        RETURN_NULL();
    }

    zai_config_id id;
    if (zai_config_get_id_by_name((zai_string_view){.ptr = ZSTR_VAL(env_name), .len = ZSTR_LEN(env_name)}, &id)) {
        ZVAL_COPY(return_value, zai_config_get_value(id));
    } else {
        RETURN_NULL();
    }
}

// This function allows untracing a function.
static PHP_FUNCTION(dd_untrace) {
    UNUSED(execute_data);

    if (!get_DD_TRACE_ENABLED()) {
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

    ddtrace_disable_tracing_in_current_request();

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_trace_reset) {
    UNUSED(execute_data);

    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

    ddtrace_dispatch_reset();
    RETURN_BOOL(1);
}

/* {{{ proto string dd_trace_serialize_msgpack(array trace_array) */
static PHP_FUNCTION(dd_trace_serialize_msgpack) {
    UNUSED(execute_data);

    if (!get_DD_TRACE_ENABLED()) {
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

    if (!get_DD_TRACE_ENABLED()) {
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
    RETURN_BOOL(ddtrace_is_memory_under_limit());
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
    zend_string *default_app_name = NULL, *app_name = get_DD_SERVICE();
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|S", &default_app_name) != SUCCESS) {
        RETURN_NULL();
    }

    if (default_app_name == NULL && ZSTR_LEN(app_name) == 0) {
        RETURN_NULL();
    }

    RETURN_STR(php_trim(ZSTR_LEN(app_name) ? app_name : default_app_name, NULL, 0, 3));
}

static PHP_FUNCTION(ddtrace_config_distributed_tracing_enabled) {
    UNUSED(execute_data);
    RETURN_BOOL(get_DD_DISTRIBUTED_TRACING());
}

static PHP_FUNCTION(ddtrace_config_trace_enabled) {
    UNUSED(execute_data);
    RETURN_BOOL(get_DD_TRACE_ENABLED());
}

static PHP_FUNCTION(ddtrace_config_integration_enabled) {
    ddtrace_string name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &name.ptr, &name.len) != SUCCESS) {
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_FALSE;
    }

    ddtrace_integration *integration = ddtrace_get_integration_from_string(name);
    if (integration == NULL) {
        RETURN_TRUE;
    }
    RETVAL_BOOL(ddtrace_config_integration_enabled(integration->name));
}

static PHP_FUNCTION(integration_analytics_enabled) {
    ddtrace_string name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &name.ptr, &name.len) != SUCCESS) {
        RETURN_NULL();
    }
    ddtrace_integration *integration = ddtrace_get_integration_from_string(name);
    if (integration == NULL) {
        RETURN_FALSE;
    }
    RETVAL_BOOL(integration->is_analytics_enabled());
}

static PHP_FUNCTION(integration_analytics_sample_rate) {
    ddtrace_string name;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &name.ptr, &name.len) != SUCCESS) {
        RETURN_NULL();
    }
    ddtrace_integration *integration = ddtrace_get_integration_from_string(name);
    if (integration == NULL) {
        RETURN_DOUBLE(DD_INTEGRATION_ANALYTICS_SAMPLE_RATE_DEFAULT);
    }
    RETVAL_DOUBLE(integration->get_sample_rate());
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
    if (get_DD_TRACE_ENABLED() && zend_parse_parameters(ZEND_NUM_ARGS(), "s", &dir.ptr, &dir.len) == SUCCESS) {
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
    if (!get_DD_TRACE_ENABLED()) {
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
        if (FUNCTION_NAME_MATCHES("init_and_start_writer")) {
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
        } else if (params_count == 2 && FUNCTION_NAME_MATCHES("root_span_add_tag")) {
            zval *tag = ZVAL_VARARG_PARAM(params, 0);
            zval *value = ZVAL_VARARG_PARAM(params, 1);
            if (Z_TYPE_P(tag) == IS_STRING && Z_TYPE_P(value) == IS_STRING) {
                RETVAL_BOOL(ddtrace_root_span_add_tag(Z_STR_P(tag), value));
            }
        }
    }
}

/* {{{ proto string dd_trace_set_trace_id() */
static PHP_FUNCTION(dd_trace_set_trace_id) {
    UNUSED(execute_data);

    zval *trace_id = NULL;
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "|z!", &trace_id) == SUCCESS) {
        if (ddtrace_set_userland_trace_id(trace_id) == true) {
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
        if (ddtrace_push_userland_span_id(existing_id) == true) {
            RETURN_STR(ddtrace_span_id_as_string(ddtrace_peek_span_id()));
        }
    }

    RETURN_STR(ddtrace_span_id_as_string(ddtrace_push_span_id(0)));
}

/* {{{ proto string dd_trace_pop_span_id() */
static PHP_FUNCTION(dd_trace_pop_span_id) {
    UNUSED(execute_data);
    uint64_t id = ddtrace_pop_span_id();

    if (DDTRACE_G(span_ids_top) == NULL && get_DD_TRACE_AUTO_FLUSH_ENABLED()) {
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
    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }
    if (!DDTRACE_G(open_spans_top)) {
        if (get_DD_TRACE_GENERATE_ROOT_SPAN()) {
            ddtrace_push_root_span();  // ensure root span always exists, especially after serialization for testing
        } else {
            RETURN_NULL();
        }
    }
    // To be removed once the new hooking mechanism is implemented
    ddtrace_span_fci *span_fci = DDTRACE_G(open_spans_top);
    while (span_fci->span.start == 0 && span_fci->next) {  // skip placeholder span from dd_create_duplicate_span
        span_fci = span_fci->next;
    }
    zend_object *obj = &span_fci->span.std;
    GC_ADDREF(obj);
    RETURN_OBJ(obj);
}

/* {{{ proto string DDTrace\root_span() */
static PHP_FUNCTION(root_span) {
    UNUSED(execute_data);
    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }
    if (!DDTRACE_G(root_span)) {
        if (get_DD_TRACE_GENERATE_ROOT_SPAN()) {
            ddtrace_push_root_span();  // ensure root span always exists, especially after serialization for testing
        } else {
            RETURN_NULL();
        }
    }
    zend_object *obj = &DDTRACE_G(root_span)->span.std;
    GC_ADDREF(obj);
    RETURN_OBJ(obj);
}

/* {{{ proto string DDTrace\start_span() */
static PHP_FUNCTION(start_span) {
    double start_time_seconds = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|d", &start_time_seconds) != SUCCESS) {
        ddtrace_log_debug("unexpected parameter. expecting double for start time");
        RETURN_FALSE;
    }

    ddtrace_span_fci *span_fci = ddtrace_init_span();

    if (get_DD_TRACE_ENABLED()) {
        GC_ADDREF(&span_fci->span.std);
        ddtrace_open_span(span_fci);
    }

    if (start_time_seconds > 0) {
        span_fci->span.start = (uint64_t)(start_time_seconds * 1000000000);
    }

    RETURN_OBJ(&span_fci->span.std);
}

/* {{{ proto string DDTrace\close_span() */
static PHP_FUNCTION(close_span) {
    double finish_time_seconds = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|d", &finish_time_seconds) != SUCCESS) {
        ddtrace_log_debug("unexpected parameter. expecting double for finish time");
        RETURN_FALSE;
    }

    if (!DDTRACE_G(open_spans_top) || DDTRACE_G(open_spans_top)->execute_data ||
        (get_DD_TRACE_GENERATE_ROOT_SPAN() && DDTRACE_G(open_spans_top)->next == NULL)) {
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

    zval zv;

    // Add Version
    ZVAL_STR_COPY(&zv, get_DD_VERSION());
    if (Z_STRLEN(zv) == 0) {
        zend_string_release(Z_STR(zv));
        ZVAL_NULL(&zv);
    }
    add_assoc_zval_ex(return_value, ZEND_STRL("version"), &zv);

    // Add Env
    ZVAL_STR_COPY(&zv, get_DD_ENV());
    if (Z_STRLEN(zv) == 0) {
        zend_string_release(Z_STR(zv));
        ZVAL_NULL(&zv);
    }
    add_assoc_zval_ex(return_value, ZEND_STRL("env"), &zv);

    if (DDTRACE_G(dd_origin)) {
        add_assoc_str_ex(return_value, ZEND_STRL("distributed_tracing_origin"), zend_string_copy(DDTRACE_G(dd_origin)));
    }

    if (DDTRACE_G(distributed_parent_trace_id)) {
        add_assoc_str_ex(return_value, ZEND_STRL("distributed_tracing_parent_id"),
                         zend_strpprintf(DD_TRACE_MAX_ID_LEN, "%" PRIu64, DDTRACE_G(distributed_parent_trace_id)));
    }

    zval tags;
    if (get_DD_TRACE_ENABLED()) {
        tags = ddtrace_get_propagated_tags();
    } else {
        array_init(&tags);
    }
    add_assoc_zval_ex(return_value, ZEND_STRL("distributed_tracing_propagated_tags"), &tags);
}

/* {{{ proto bool set_distributed_tracing_context() */
static PHP_FUNCTION(set_distributed_tracing_context) {
    zend_string *trace_id_str, *parent_id_str, *origin = NULL;
    zval *tags = NULL;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "SS|S!z!", &trace_id_str, &parent_id_str,
                                 &origin, &tags) != SUCCESS ||
        (tags && Z_TYPE_P(tags) > IS_FALSE && Z_TYPE_P(tags) != IS_ARRAY && Z_TYPE_P(tags) != IS_STRING)) {
        ddtrace_log_debug(
            "unexpected parameter. expecting string trace id and string parent id and possibly string origin and string or array propagated tags");
        RETURN_FALSE;
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_FALSE;
    }

    if (DDTRACE_G(open_spans_top)) {
        ddtrace_log_debug("Cannot set the distributed tracing context when there are active spans");
        RETURN_FALSE;
    }

    uint64_t old_trace_id = DDTRACE_G(trace_id);
    zval trace_zv;
    ZVAL_STR(&trace_zv, trace_id_str);
    if (ZSTR_LEN(trace_id_str) == 1 && ZSTR_VAL(trace_id_str)[0] == '0') {
        DDTRACE_G(trace_id) = 0;
    } else if (!ddtrace_set_userland_trace_id(&trace_zv)) {
        RETURN_FALSE;
    }

    zval parent_zv;
    ZVAL_STR(&parent_zv, parent_id_str);
    uint64_t last_id = ddtrace_pop_span_id();
    if (ZSTR_LEN(parent_id_str) == 1 && ZSTR_VAL(parent_id_str)[0] == '0') {
        DDTRACE_G(distributed_parent_trace_id) = 0;
    } else if (ddtrace_push_userland_span_id(&parent_zv)) {
        DDTRACE_G(distributed_parent_trace_id) = ddtrace_peek_span_id();
    } else {
        ddtrace_push_span_id(last_id);
        DDTRACE_G(trace_id) = old_trace_id;
        RETURN_FALSE;
    }

    if (origin) {
        if (DDTRACE_G(dd_origin)) {
            zend_string_release(DDTRACE_G(dd_origin));
        }
        DDTRACE_G(dd_origin) = ZSTR_LEN(origin) ? zend_string_copy(origin) : NULL;
    }

    if (tags) {
        if (Z_TYPE_P(tags) == IS_STRING) {
            ddtrace_add_tracer_tags_from_header(Z_STR_P(tags));
        } else if (Z_TYPE_P(tags) == IS_ARRAY) {
            ddtrace_add_tracer_tags_from_array(Z_ARR_P(tags));
        }
    }

    RETURN_TRUE;
}

/* {{{ proto string dd_trace_closed_spans_count() */
static PHP_FUNCTION(dd_trace_closed_spans_count) {
    UNUSED(execute_data);
    RETURN_LONG(DDTRACE_G(closed_spans_count));
}

bool ddtrace_tracer_is_limited(void) {
    int64_t limit = get_DD_TRACE_SPANS_LIMIT();
    if (limit >= 0) {
        int64_t open_spans = DDTRACE_G(open_spans_count);
        int64_t closed_spans = DDTRACE_G(closed_spans_count);
        if ((open_spans + closed_spans) >= limit) {
            return true;
        }
    }
    return !ddtrace_is_memory_under_limit();
}

/* {{{ proto string dd_trace_tracer_is_limited() */
static PHP_FUNCTION(dd_trace_tracer_is_limited) {
    UNUSED(execute_data);
    RETURN_BOOL(ddtrace_tracer_is_limited() == true ? 1 : 0);
}

/* {{{ proto string dd_trace_compile_time_microseconds() */
static PHP_FUNCTION(dd_trace_compile_time_microseconds) {
    UNUSED(execute_data);
    RETURN_LONG(ddtrace_compile_time_get());
}

static PHP_FUNCTION(set_priority_sampling) {
    bool global = false;
    zend_long priority;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "l|b", &priority, &global) == FAILURE) {
        ddtrace_log_debug("Expected an integer and an optional boolean");
        RETURN_FALSE;
    }

    if (global || !DDTRACE_G(root_span)) {
        DDTRACE_G(default_priority_sampling) = priority;
    } else {
        ddtrace_set_prioritySampling_on_root(priority);
    }
}

static PHP_FUNCTION(get_priority_sampling) {
    zend_bool global = false;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "|b", &global) == FAILURE) {
        ddtrace_log_debug("Expected an optional boolean");
        RETURN_NULL();
    }

    if (global || !DDTRACE_G(root_span)) {
        RETURN_LONG(DDTRACE_G(default_priority_sampling));
    }

    RETURN_LONG(ddtrace_fetch_prioritySampling_from_root());
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
    DDTRACE_NS_FE(root_span, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_peek_span_id, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_pop_span_id, arginfo_ddtrace_void),
    DDTRACE_FE(dd_trace_push_span_id, arginfo_dd_trace_push_span_id),
    DDTRACE_NS_FE(trace_id, arginfo_ddtrace_void),
    DDTRACE_NS_FE(current_context, arginfo_ddtrace_void),
    DDTRACE_NS_FE(set_distributed_tracing_context, arginfo_dd_trace_set_distributed_tracing_context),
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
    DDTRACE_NS_FE(get_priority_sampling, arginfo_get_priority_sampling),
    DDTRACE_NS_FE(set_priority_sampling, arginfo_set_priority_sampling),
    DDTRACE_SUB_NS_FE("Config\\", integration_analytics_enabled, arginfo_ddtrace_config_integration_analytics_enabled),
    DDTRACE_SUB_NS_FE("Config\\", integration_analytics_sample_rate,
                      arginfo_ddtrace_config_integration_analytics_sample_rate),
    DDTRACE_SUB_NS_FE("System\\", container_id, arginfo_ddtrace_void),
    DDTRACE_SUB_NS_FE("Testing\\", trigger_error, arginfo_ddtrace_testing_trigger_error),
    DDTRACE_FE_END};

static const zend_module_dep ddtrace_module_deps[] = {ZEND_MOD_REQUIRED("json") ZEND_MOD_REQUIRED("standard")
                                                          ZEND_MOD_END};

zend_module_entry ddtrace_module_entry = {STANDARD_MODULE_HEADER_EX, NULL,
                                          ddtrace_module_deps,       PHP_DDTRACE_EXTNAME,
                                          ddtrace_functions,         PHP_MINIT(ddtrace),
                                          PHP_MSHUTDOWN(ddtrace),    PHP_RINIT(ddtrace),
                                          PHP_RSHUTDOWN(ddtrace),    PHP_MINFO(ddtrace),
                                          PHP_DDTRACE_VERSION,       PHP_MODULE_GLOBALS(ddtrace),
                                          PHP_GINIT(ddtrace),        NULL,
                                          ddtrace_post_deactivate,   STANDARD_MODULE_PROPERTIES_EX};

// the following operations are performed in order to put the tracer in a state when a new trace can be started:
//   - set a new trace (group) id
void dd_prepare_for_new_trace(void) { DDTRACE_G(traces_group_id) = ddtrace_coms_next_group_id(); }

void dd_read_distributed_tracing_ids(void) {
    zend_string *trace_id_str, *parent_id_str, *priority_str, *propagated_tags;
    bool success = true;

    if (zai_read_header_literal("X_DATADOG_TRACE_ID", &trace_id_str) == ZAI_HEADER_SUCCESS) {
        if (ZSTR_LEN(trace_id_str) != 1 || ZSTR_VAL(trace_id_str)[0] != '0') {
            zval trace_zv;
            ZVAL_STR(&trace_zv, trace_id_str);
            success = ddtrace_set_userland_trace_id(&trace_zv);
        }
    }

    DDTRACE_G(distributed_parent_trace_id) = 0;
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

    DDTRACE_G(dd_origin) = NULL;
    if (zai_read_header_literal("X_DATADOG_ORIGIN", &DDTRACE_G(dd_origin)) == ZAI_HEADER_SUCCESS) {
        GC_ADDREF(DDTRACE_G(dd_origin));
    }

    if (zai_read_header_literal("X_DATADOG_SAMPLING_PRIORITY", &priority_str) == ZAI_HEADER_SUCCESS) {
        DDTRACE_G(propagated_priority_sampling) = DDTRACE_G(default_priority_sampling) =
            strtol(ZSTR_VAL(priority_str), NULL, 10);
    }

    if (zai_read_header_literal("X_DATADOG_TAGS", &propagated_tags) == ZAI_HEADER_SUCCESS) {
        ddtrace_add_tracer_tags_from_header(propagated_tags);
    }
}
