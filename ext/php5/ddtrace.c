#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include <SAPI.h>
#include <Zend/zend.h>
#include <Zend/zend_builtin_functions.h>
#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_extensions.h>
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
#include <ext/standard/php_smart_str.h>
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

atomic_int ddtrace_warn_legacy_api;

ZEND_DECLARE_MODULE_GLOBALS(ddtrace)

#ifdef COMPILE_DL_DDTRACE
ZEND_GET_MODULE(ddtrace)
#endif

PHP_INI_BEGIN()
STD_PHP_INI_BOOLEAN("ddtrace.disable", "0", PHP_INI_SYSTEM, OnUpdateBool, disable, zend_ddtrace_globals,
                    ddtrace_globals)

// Exposed for testing only
STD_PHP_INI_ENTRY("ddtrace.cgroup_file", "/proc/self/cgroup", PHP_INI_SYSTEM, OnUpdateString, cgroup_file,
                  zend_ddtrace_globals, ddtrace_globals)
PHP_INI_END()

static int ddtrace_startup(struct _zend_extension *extension) {
    TSRMLS_FETCH();

    ddtrace_resource = zend_get_resource_handle(extension);

    ddtrace_excluded_modules_startup();
    // We deliberately leave handler replacement during startup, even though this uses some config
    // This touches global state, which, while unlikely, may play badly when interacting with other extensions, if done
    // post-startup
    ddtrace_internal_handlers_startup(TSRMLS_C);
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
#ifdef ZTS
    UNUSED(TSRMLS_C);
#endif
    php_ddtrace_init_globals(ddtrace_globals);
}

/* DDTrace\SpanData */
zend_class_entry *ddtrace_ce_span_data;
zend_object_handlers ddtrace_span_data_handlers;

static void ddtrace_span_data_free_storage(void *object_ptr TSRMLS_DC) {
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)object_ptr;
    if (span_fci->dispatch) {
        ddtrace_dispatch_release(span_fci->dispatch);
        span_fci->dispatch = NULL;
    }
    zend_object_std_dtor(&span_fci->span.std TSRMLS_CC);
    efree(span_fci);
}

static zend_object_value ddtrace_span_data_create(zend_class_entry *class_type TSRMLS_DC) {
    ddtrace_span_fci *span_fci = ecalloc(1, sizeof(*span_fci));
    zend_object_std_init(&span_fci->span.std, class_type TSRMLS_CC);
    span_fci->span.std.properties_table = ecalloc(class_type->default_properties_count, sizeof(zval *));
    span_fci->span.std.properties = NULL;
    // ensure array initialized
    ddtrace_spandata_property_meta(&span_fci->span);
    ddtrace_spandata_property_metrics(&span_fci->span);
    return span_fci->span.obj_value = (zend_object_value){
               .handle = zend_objects_store_put(span_fci, NULL, ddtrace_span_data_free_storage, NULL TSRMLS_CC),
               .handlers = &ddtrace_span_data_handlers};
}

static zend_object_value ddtrace_span_data_clone_obj(zval *old_zv TSRMLS_DC) {
    zend_object *old_object = zend_objects_get_address(old_zv TSRMLS_CC);
    zend_object_value new_obj_val = ddtrace_span_data_create(old_object->ce TSRMLS_CC);
    zend_object *new_object = zend_object_store_get_object_by_handle(new_obj_val.handle TSRMLS_CC);

    zend_objects_clone_members(new_object, new_obj_val, old_object, Z_OBJ_HANDLE_P(old_zv) TSRMLS_CC);

    return new_obj_val;
}

static void ddtrace_span_data_readonly(zval *object, zval *member, zval *value, const zend_literal *key TSRMLS_DC) {
    if (Z_TYPE_P(member) == IS_STRING &&
        ((Z_STRLEN_P(member) == sizeof("parent") - 1 &&
          SUCCESS == memcmp(Z_STRVAL_P(member), "parent", sizeof("parent") - 1)) ||
         (Z_STRLEN_P(member) == sizeof("id") - 1 && SUCCESS == memcmp(Z_STRVAL_P(member), "id", sizeof("id") - 1)))) {
        zend_throw_exception_ex(NULL, 0 TSRMLS_CC, "Cannot modify readonly property %s::$%s", Z_OBJCE_P(object)->name,
                                Z_STRVAL_P(member));
        return;
    }

    zend_std_write_property(object, member, value, key TSRMLS_CC);
}

static PHP_METHOD(DDTrace_SpanData, getDuration) {
    UNUSED(ht, return_value_used, this_ptr, return_value_ptr);
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)zend_object_store_get_object(getThis() TSRMLS_CC);
    RETURN_LONG(span_fci->span.duration);
}

static PHP_METHOD(DDTrace_SpanData, getStartTime) {
    UNUSED(ht, return_value_used, this_ptr, return_value_ptr);
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)zend_object_store_get_object(getThis() TSRMLS_CC);
    RETURN_LONG(span_fci->span.start);
}

const zend_function_entry class_DDTrace_SpanData_methods[] = {
    // clang-format off
    PHP_ME(DDTrace_SpanData, getDuration, arginfo_ddtrace_void, ZEND_ACC_PUBLIC)
    PHP_ME(DDTrace_SpanData, getStartTime, arginfo_ddtrace_void, ZEND_ACC_PUBLIC)
    PHP_FE_END
    // clang-format on
};

static void dd_register_span_data_ce(TSRMLS_D) {
    memcpy(&ddtrace_span_data_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    ddtrace_span_data_handlers.clone_obj = ddtrace_span_data_clone_obj;
    ddtrace_span_data_handlers.write_property = ddtrace_span_data_readonly;

    zend_class_entry ce_span_data;
    INIT_NS_CLASS_ENTRY(ce_span_data, "DDTrace", "SpanData", class_DDTrace_SpanData_methods);
    ddtrace_ce_span_data = zend_register_internal_class(&ce_span_data TSRMLS_CC);
    ddtrace_ce_span_data->create_object = ddtrace_span_data_create;

    // trace_id, span_id, parent_id, start & duration are stored directly on
    // ddtrace_span_t so we don't need to make them properties on DDTrace\SpanData
    /*
     * ORDER MATTERS: If you make any changes to the properties below, update the
     * corresponding ddtrace_spandata_property_*() function with the proper offset.
     */
    zend_declare_property_null(ddtrace_ce_span_data, "name", sizeof("name") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "resource", sizeof("resource") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "service", sizeof("service") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "type", sizeof("type") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "meta", sizeof("meta") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "metrics", sizeof("metrics") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "exception", sizeof("exception") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "parent", sizeof("parent") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "id", sizeof("id") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
}

static zval *OBJ_PROP_NUM(zend_object *obj, uint32_t offset) {
    if (obj->properties) {
        return obj->properties_table[offset] ? *(zval **)obj->properties_table[offset] : NULL;
    } else {
        return obj->properties_table[offset];
    }
}

static zval **OBJ_PROP_NUM_write(zend_object *obj, uint32_t offset) {
    zval **zv;
    if (obj->properties) {
        zv = (zval **)obj->properties_table[offset];
        if (!zv) {
            zend_property_info *prop_info = ddtrace_ce_span_data->properties_info.arBuckets[offset]->pData;
            zend_hash_quick_update(obj->properties, prop_info->name, prop_info->name_length + 1, prop_info->h, NULL,
                                   sizeof(zval *), (void **)&zv);
            obj->properties_table[offset] = (zval *)zv;
        }
    } else {
        return &obj->properties_table[offset];
    }
    return zv;
}

static zval *OBJ_PROP_NUM_array_init(zend_object *obj, uint32_t offset) {
    zval **zv = OBJ_PROP_NUM_write(obj, offset);
    if (*zv && Z_TYPE_PP(zv) == IS_ARRAY) {
        return *zv;
    }
    if (*zv) {
        zval *oldzv = *zv;
        MAKE_STD_ZVAL(*zv);
        array_init(*zv);
        zval_ptr_dtor(&oldzv);
    } else {
        MAKE_STD_ZVAL(*zv);
        array_init(*zv);
    }
    return *zv;
}

// SpanData::$name
zval *ddtrace_spandata_property_name(ddtrace_span_t *span) { return OBJ_PROP_NUM(&span->std, 0); }
zval **ddtrace_spandata_property_name_write(ddtrace_span_t *span) { return OBJ_PROP_NUM_write(&span->std, 0); }
// SpanData::$resource
zval *ddtrace_spandata_property_resource(ddtrace_span_t *span) { return OBJ_PROP_NUM(&span->std, 1); }
zval **ddtrace_spandata_property_resource_write(ddtrace_span_t *span) { return OBJ_PROP_NUM_write(&span->std, 1); }
// SpanData::$service
zval *ddtrace_spandata_property_service(ddtrace_span_t *span) { return OBJ_PROP_NUM(&span->std, 2); }
zval **ddtrace_spandata_property_service_write(ddtrace_span_t *span) { return OBJ_PROP_NUM_write(&span->std, 2); }
// SpanData::$type
zval *ddtrace_spandata_property_type(ddtrace_span_t *span) { return OBJ_PROP_NUM(&span->std, 3); }
zval **ddtrace_spandata_property_type_write(ddtrace_span_t *span) { return OBJ_PROP_NUM_write(&span->std, 3); }
// SpanData::$meta
zval *ddtrace_spandata_property_meta(ddtrace_span_t *span) { return OBJ_PROP_NUM_array_init(&span->std, 4); }
// SpanData::$metrics
zval *ddtrace_spandata_property_metrics(ddtrace_span_t *span) { return OBJ_PROP_NUM_array_init(&span->std, 5); }
// SpanData::$exception
zval *ddtrace_spandata_property_exception(ddtrace_span_t *span) { return OBJ_PROP_NUM(&span->std, 6); }
zval **ddtrace_spandata_property_exception_write(ddtrace_span_t *span) { return OBJ_PROP_NUM_write(&span->std, 6); }
// SpanData::$parent
zval **ddtrace_spandata_property_parent_write(ddtrace_span_t *span) { return OBJ_PROP_NUM_write(&span->std, 7); }
// SpanData::$id
zval **ddtrace_spandata_property_id_write(ddtrace_span_t *span) { return OBJ_PROP_NUM_write(&span->std, 8); }

static zend_object_handlers ddtrace_fatal_error_handlers;
/* The goal is to mimic zend_default_exception_new_ex except for adding
 * DEBUG_BACKTRACE_IGNORE_ARGS to zend_fetch_debug_backtrace. We don't want the
 * args as they could leak info, and the serializer will throw them away anyway.
 * Additionally, the tests leaked an argument in zend_fetch_debug_backtrace,
 * which was the straw to break the camel's back.
 */
static zend_object_value ddtrace_fatal_error_new(zend_class_entry *class_type TSRMLS_DC) {
    zval obj;
    zend_object *object;
    zval *trace;

    Z_OBJVAL(obj) = zend_objects_new(&object, class_type TSRMLS_CC);
    Z_OBJ_HT(obj) = &ddtrace_fatal_error_handlers;

    object_properties_init(object, class_type);

    ALLOC_ZVAL(trace);
    Z_UNSET_ISREF_P(trace);
    Z_SET_REFCOUNT_P(trace, 0);
    zend_fetch_debug_backtrace(trace, 0, DEBUG_BACKTRACE_IGNORE_ARGS, 0 TSRMLS_CC);

    zend_class_entry *exception_ce = zend_exception_get_default(TSRMLS_C);
    zend_update_property_string(exception_ce, &obj, "file", sizeof("file") - 1,
                                zend_get_executed_filename(TSRMLS_C) TSRMLS_CC);
    zend_update_property_long(exception_ce, &obj, "line", sizeof("line") - 1,
                              zend_get_executed_lineno(TSRMLS_C) TSRMLS_CC);
    zend_update_property(exception_ce, &obj, "trace", sizeof("trace") - 1, trace TSRMLS_CC);

    return Z_OBJVAL(obj);
}

/* DDTrace\FatalError */
zend_class_entry *ddtrace_ce_fatal_error;

static void dd_register_fatal_error_ce(TSRMLS_D) {
    zend_class_entry ce;
    INIT_NS_CLASS_ENTRY(ce, "DDTrace", "FatalError", NULL);
    ddtrace_ce_fatal_error = zend_register_internal_class_ex(&ce, zend_exception_get_default(TSRMLS_C), NULL TSRMLS_CC);
    ddtrace_ce_fatal_error->create_object = ddtrace_fatal_error_new;
    // these mimic zend_register_default_exception
    memcpy(&ddtrace_fatal_error_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
    ddtrace_fatal_error_handlers.clone_obj = NULL;
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

static void dd_disable_if_incompatible_sapi_detected(TSRMLS_D) {
    datadog_php_string_view module_name = datadog_php_string_view_from_cstr(sapi_module.name);
    if (UNEXPECTED(!dd_is_compatible_sapi(module_name))) {
        ddtrace_log_debugf("Incompatible SAPI detected '%s'; disabling ddtrace", sapi_module.name);
        DDTRACE_G(disable) = 1;
    }
}

static void dd_read_distributed_tracing_ids(TSRMLS_D);

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

    // config initialization needs to be at the top
    if (!ddtrace_config_minit(module_number)) {
        return FAILURE;
    }
    dd_disable_if_incompatible_sapi_detected(TSRMLS_C);
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

    ddtrace_dogstatsd_client_minit(TSRMLS_C);
    ddshared_minit(TSRMLS_C);

    dd_register_span_data_ce(TSRMLS_C);
    dd_register_fatal_error_ce(TSRMLS_C);

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
    TSRMLS_FETCH();

    /* The env vars are memoized on MINIT before the SAPI env vars are available.
     * We use the first RINIT to bust the env var cache and use the SAPI env vars.
     * TODO Audit/remove config usages before RINIT and move config init to RINIT.
     */
    ddtrace_startup_logging_first_rinit(TSRMLS_C);

    // Uses config, cannot run earlier
    ddtrace_signals_first_rinit(TSRMLS_C);
    ddtrace_coms_init_and_start_writer();
}

static pthread_once_t dd_rinit_config_once_control = PTHREAD_ONCE_INIT;
static pthread_once_t dd_rinit_once_control = PTHREAD_ONCE_INIT;

static void dd_initialize_request(TSRMLS_D) {
    array_init_size(&DDTRACE_G(additional_trace_meta), ddtrace_num_error_tags);
    zend_hash_init(&DDTRACE_G(additional_global_tags), 8, NULL, ZVAL_PTR_DTOR, 0);
    DDTRACE_G(default_priority_sampling) = DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
    DDTRACE_G(propagated_priority_sampling) = DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
    zend_hash_init(&DDTRACE_G(root_span_tags_preset), 8, NULL, ZVAL_PTR_DTOR, 0);
    zend_hash_init(&DDTRACE_G(propagated_root_span_tags), 8, NULL, NULL, 0);

    // Things that should only run on the first RINIT
    pthread_once(&dd_rinit_once_control, dd_rinit_once);

    if (get_DD_TRACE_REQUEST_INIT_HOOK().len) {
        dd_request_init_hook_rinit(TSRMLS_C);
    }

    ddtrace_engine_hooks_rinit(TSRMLS_C);
    ddtrace_internal_handlers_rinit(TSRMLS_C);
    ddtrace_bgs_log_rinit(PG(error_log));
    ddtrace_dispatch_init(TSRMLS_C);

    // This allows us to hook the ZEND_HANDLE_EXCEPTION pseudo opcode
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op));
    EG(exception_op)->opcode = ZEND_HANDLE_EXCEPTION;

    ddtrace_dogstatsd_client_rinit(TSRMLS_C);

    ddtrace_seed_prng(TSRMLS_C);
    ddtrace_init_span_id_stack(TSRMLS_C);
    ddtrace_init_span_stacks(TSRMLS_C);
    ddtrace_coms_on_pid_change();

    // Initialize C integrations and deferred loading
    ddtrace_integrations_rinit(TSRMLS_C);

    // Reset compile time after request init hook has compiled
    ddtrace_compile_time_reset(TSRMLS_C);

    dd_prepare_for_new_trace(TSRMLS_C);

    dd_read_distributed_tracing_ids(TSRMLS_C);

    if (get_DD_TRACE_GENERATE_ROOT_SPAN()) {
        ddtrace_push_root_span(TSRMLS_C);
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

    dd_initialize_request(TSRMLS_C);

    return SUCCESS;
}

static void dd_clean_globals(TSRMLS_D) {
    zval_dtor(&DDTRACE_G(additional_trace_meta));
    zend_hash_destroy(&DDTRACE_G(additional_global_tags));
    zend_hash_destroy(&DDTRACE_G(root_span_tags_preset));
    zend_hash_destroy(&DDTRACE_G(propagated_root_span_tags));
    ZVAL_NULL(&DDTRACE_G(additional_trace_meta));

    if (DDTRACE_G(dd_origin)) {
        str_efree(DDTRACE_G(dd_origin));
    }

    ddtrace_engine_hooks_rshutdown(TSRMLS_C);
    ddtrace_internal_handlers_rshutdown(TSRMLS_C);
    ddtrace_dogstatsd_client_rshutdown(TSRMLS_C);

    ddtrace_free_span_stacks(TSRMLS_C);
    ddtrace_coms_rshutdown();

    if (get_DD_TRACE_REQUEST_INIT_HOOK().len) {
        dd_request_init_hook_rshutdown(TSRMLS_C);
    }
}

static PHP_RSHUTDOWN_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

    zai_hook_rshutdown();

    if (!get_DD_TRACE_ENABLED()) {
        ddtrace_dispatch_destroy(TSRMLS_C);
        ddtrace_free_span_id_stack(TSRMLS_C);

        return SUCCESS;
    }

    ddtrace_close_all_open_spans(TSRMLS_C);  // All remaining non-internal userland spans
    if (DDTRACE_G(open_spans_top) && DDTRACE_G(open_spans_top)->execute_data == NULL) {
        // we have a root span. Close it.
        dd_trace_stop_span_time(&DDTRACE_G(open_spans_top)->span);
        ddtrace_close_span(DDTRACE_G(open_spans_top) TSRMLS_CC);
    }
    if (!ddtrace_flush_tracer(TSRMLS_C)) {
        ddtrace_log_debug("Unable to flush the tracer");
    }

    dd_clean_globals(TSRMLS_C);

    ddtrace_dispatch_destroy(TSRMLS_C);
    ddtrace_free_span_id_stack(TSRMLS_C);

    return SUCCESS;
}

int ddtrace_post_deactivate(void) {
    // zai config may be accessed indirectly via other modules RSHUTDOWN, so delay this until the last possible time
    zai_config_rshutdown();
    return SUCCESS;
}

void ddtrace_disable_tracing_in_current_request(void) {
    zend_ini_entry *ini = zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_ENABLED].ini_entries[0];
    zend_alter_ini_entry(ini->name, ini->name_length, "0", 1, ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME);
}

bool ddtrace_alter_dd_trace_disabled_config(zval *old_value, zval *new_value) {
    if (Z_BVAL_P(old_value) == Z_BVAL_P(new_value)) {
        return true;
    }

    TSRMLS_FETCH();

    if (DDTRACE_G(disable)) {
        return Z_BVAL_P(new_value) == 0;  // no changing to enabled allowed if globally disabled
    }

    if (Z_BVAL_P(old_value) == 0) {
        dd_initialize_request(TSRMLS_C);
    } else if (!DDTRACE_G(disable)) {  // if this is true, the request has not been initialized at all
        ddtrace_close_all_open_spans(TSRMLS_C);
        dd_clean_globals(TSRMLS_C);
    }

    return true;
}

static int datadog_info_print(const char *str TSRMLS_DC) { return php_output_write(str, strlen(str) TSRMLS_CC); }

static void _dd_info_tracer_config(void) {
    smart_str buf = {0};
    ddtrace_startup_logging_json(&buf);
    php_info_print_table_row(2, "DATADOG TRACER CONFIGURATION", buf.c);
    smart_str_free(&buf);
}

static void _dd_info_diagnostics_row(const char *key, const char *value TSRMLS_DC) {
    if (sapi_module.phpinfo_as_text) {
        php_info_print_table_row(2, key, value);
        return;
    }
    datadog_info_print("<tr><td class='e'>" TSRMLS_CC);
    datadog_info_print(key TSRMLS_CC);
    datadog_info_print("</td><td class='v' style='background-color:#f0e881;'>" TSRMLS_CC);
    datadog_info_print(value TSRMLS_CC);
    datadog_info_print("</td></tr>" TSRMLS_CC);
}

static void _dd_info_diagnostics_table(TSRMLS_D) {
    php_info_print_table_start();
    php_info_print_table_colspan_header(2, "Diagnostics");

    HashTable *ht;
    ALLOC_HASHTABLE(ht);
    zend_hash_init(ht, 8, NULL, ZVAL_PTR_DTOR, 0);

    ddtrace_startup_diagnostics(ht, false);

    int key_type;
    zval **val;
    HashPosition pos;
    char *key;
    uint key_len;
    ulong num_key;
    zend_hash_internal_pointer_reset_ex(ht, &pos);
    while (zend_hash_get_current_data_ex(ht, (void **)&val, &pos) == SUCCESS) {
        key_type = zend_hash_get_current_key_ex(ht, &key, &key_len, &num_key, 0, &pos);
        if (key_type == HASH_KEY_IS_STRING) {
            switch (Z_TYPE_PP(val)) {
                case IS_STRING:
                    _dd_info_diagnostics_row(key, Z_STRVAL_PP(val) TSRMLS_CC);
                    break;
                case IS_NULL:
                    _dd_info_diagnostics_row(key, "NULL" TSRMLS_CC);
                    break;
                case IS_BOOL:
                    _dd_info_diagnostics_row(key, Z_BVAL_PP(val) ? "true" : "false" TSRMLS_CC);
                    break;
                default:
                    _dd_info_diagnostics_row(key, "{unknown type}" TSRMLS_CC);
                    break;
            }
        }
        zend_hash_move_forward_ex(ht, &pos);
    }

    php_info_print_table_row(2, "Diagnostic checks", zend_hash_num_elements(ht) == 0 ? "passed" : "failed");

    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);

    php_info_print_table_end();
}

static PHP_MINFO_FUNCTION(ddtrace) {
    UNUSED(zend_module);

    php_info_print_box_start(0);
    datadog_info_print("Datadog PHP tracer extension" TSRMLS_CC);
    if (!sapi_module.phpinfo_as_text) {
        datadog_info_print("<br><strong>For help, check out " TSRMLS_CC);
        datadog_info_print(
            "<a href=\"https://docs.datadoghq.com/tracing/languages/php/\" "
            "style=\"background:transparent;\">the documentation</a>.</strong>" TSRMLS_CC);
    } else {
        datadog_info_print(
            "\nFor help, check out the documentation at "
            "https://docs.datadoghq.com/tracing/languages/php/" TSRMLS_CC);
    }
    datadog_info_print(!sapi_module.phpinfo_as_text ? "<br><br>" : "\n" TSRMLS_CC);
    datadog_info_print("(c) Datadog 2020\n" TSRMLS_CC);
    php_info_print_box_end();

    php_info_print_table_start();
    php_info_print_table_row(2, "Datadog tracing support", DDTRACE_G(disable) ? "disabled" : "enabled");
    php_info_print_table_row(2, "Version", PHP_DDTRACE_VERSION);
    _dd_info_tracer_config();
    php_info_print_table_end();

    if (!DDTRACE_G(disable)) {
        _dd_info_diagnostics_table(TSRMLS_C);
    }

    DISPLAY_INI_ENTRIES();
}

static bool _parse_config_array(zval *config_array, zval **tracing_closure, uint32_t *options TSRMLS_DC) {
    if (Z_TYPE_P(config_array) != IS_ARRAY) {
        ddtrace_log_debug("Expected config_array to be an associative array");
        return false;
    }

    zval **value;
    char *string_key;
    uint str_len;
    HashPosition iterator;
    zend_ulong num_key;
    int key_type;
    HashTable *ht = Z_ARRVAL_P(config_array);

    zend_hash_internal_pointer_reset_ex(ht, &iterator);
    while (zend_hash_get_current_data_ex(ht, (void **)&value, &iterator) == SUCCESS) {
        key_type = zend_hash_get_current_key_ex(ht, &string_key, &str_len, &num_key, 0, &iterator);
        if (key_type != HASH_KEY_IS_STRING || !string_key) {
            ddtrace_log_debug("Expected config_array to be an associative array");
            return false;
        }
        // TODO Optimize this
        if (strcmp("posthook", string_key) == 0) {
            if (Z_TYPE_PP(value) == IS_OBJECT && instanceof_function(Z_OBJCE_PP(value), zend_ce_closure TSRMLS_CC)) {
                *tracing_closure = *value;
                *options |= DDTRACE_DISPATCH_POSTHOOK;
            } else {
                ddtrace_log_debugf("Expected '%s' to be an instance of Closure", string_key);
                return false;
            }
        } else if (strcmp("prehook", string_key) == 0) {
            ddtrace_log_debugf("'%s' not supported on PHP 5", string_key);
            return false;
        } else if (strcmp("instrument_when_limited", string_key) == 0) {
            if (Z_TYPE_PP(value) == IS_LONG) {
                if (Z_LVAL_PP(value)) {
                    *options |= DDTRACE_DISPATCH_INSTRUMENT_WHEN_LIMITED;
                }
            } else {
                ddtrace_log_debugf("Expected '%s' to be an int", string_key);
                return false;
            }
        } else {
            ddtrace_log_debugf("Unknown option '%s' in config_array", string_key);
            return false;
        }
        zend_hash_move_forward_ex(ht, &iterator);
    }
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
    UNUSED(return_value_used, this_ptr, return_value_ptr);
    zval *function = NULL;
    zval *class_name = NULL;
    zval *callable = NULL;
    zval *config_array = NULL;

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "zzO", &class_name, &function,
                                 &callable, zend_ce_closure) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "zO", &function, &callable,
                                 zend_ce_closure) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "zza", &class_name, &function,
                                 &config_array) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "za", &function, &config_array) !=
            SUCCESS) {
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
    UNUSED(return_value_used, this_ptr, return_value_ptr);
    zval *class_name = NULL;
    zval *function = NULL;
    zval *tracing_closure = NULL;
    zval *config_array = NULL;
    uint32_t options = 0;

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "zzO", &class_name, &function,
                                 &tracing_closure, zend_ce_closure) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "zza", &class_name, &function,
                                 &config_array) != SUCCESS) {
        ddtrace_log_debug("Unexpected parameters, expected (class_name, method_name, tracing_closure | config_array)");
        RETURN_BOOL(0);
    }

    if (Z_TYPE_P(class_name) != IS_STRING || Z_TYPE_P(function) != IS_STRING) {
        ddtrace_log_debug("class_name and method_name must be a string");
        RETURN_BOOL(0);
    }

    if (config_array) {
        if (_parse_config_array(config_array, &tracing_closure, &options TSRMLS_CC) == false) {
            RETURN_BOOL(0);
        }
    } else {
        options |= DDTRACE_DISPATCH_POSTHOOK;
    }

    zend_bool rv = ddtrace_trace(class_name, function, tracing_closure, options TSRMLS_CC);
    RETURN_BOOL(rv);
}

/* Note that on PHP 5 we bind $this on the callbacks. If we don't then the VM
 * will set the static flag on the closure in certain circumstances. For
 * example, if a tracing closure is defined inside another closure that has a
 * scope, then the tracing closure will get created as static and will be
 * unable to bind to $this, as static closures cannot be bound to objects --
 * at least in PHP 5.
 *
 * In PHP 7 we don't bind $this as we want only public access.
 */
static PHP_FUNCTION(hook_method) {
    ddtrace_string classname = {.ptr = NULL, .len = 0};
    ddtrace_string funcname = {.ptr = NULL, .len = 0};
    zval *prehook = NULL, *posthook = NULL;
    UNUSED(return_value_used, this_ptr, return_value_ptr);

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "ss|O!O!", &classname.ptr,
                                 &classname.len, &funcname.ptr, &funcname.len, &prehook, zend_ce_closure, &posthook,
                                 zend_ce_closure) != SUCCESS) {
        ddtrace_log_debug(
            "Unable to parse parameters for DDTrace\\hook_method; expected "
            "(string $class_name, string $method_name, ?Closure $prehook = NULL, ?Closure $posthook = NULL)");
        RETURN_FALSE;
    }

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

    // todo: stop duplicating strings everywhere...
    zval *class_name_zv = NULL, *method_name_zv = NULL;
    MAKE_STD_ZVAL(class_name_zv);
    MAKE_STD_ZVAL(method_name_zv);
    ZVAL_STRINGL(class_name_zv, classname.ptr, classname.len, 1);
    ZVAL_STRINGL(method_name_zv, funcname.ptr, funcname.len, 1);

    zend_bool rv = ddtrace_trace(class_name_zv, method_name_zv, callable, options TSRMLS_CC);

    zval_ptr_dtor(&method_name_zv);
    zval_ptr_dtor(&class_name_zv);

    RETURN_BOOL(rv);
}

static PHP_FUNCTION(hook_function) {
    ddtrace_string funcname = {.ptr = NULL, .len = 0};
    zval *prehook = NULL, *posthook = NULL;
    UNUSED(return_value_used, this_ptr, return_value_ptr);

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "s|O!O!", &funcname.ptr,
                                 &funcname.len, &prehook, zend_ce_closure, &posthook, zend_ce_closure) != SUCCESS) {
        ddtrace_log_debug(
            "Unable to parse parameters for DDTrace\\hook_function; expected "
            "(string $method_name, ?Closure $prehook = NULL, ?Closure $posthook = NULL)");
        RETURN_FALSE;
    }

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

    zval *function_name_zv = NULL;
    MAKE_STD_ZVAL(function_name_zv);
    ZVAL_STRINGL(function_name_zv, funcname.ptr, funcname.len, 1);

    zend_bool rv = ddtrace_trace(NULL, function_name_zv, callable, options TSRMLS_CC);

    zval_ptr_dtor(&function_name_zv);
    RETURN_BOOL(rv);
}

static PHP_FUNCTION(additional_trace_meta) {
    UNUSED(return_value_used, this_ptr, return_value_ptr);

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "")) {
        ddtrace_log_debug("Unexpected parameters to DDTrace\\additional_trace_meta");
        array_init(return_value);
        return;
    }

    ZVAL_COPY_VALUE(return_value, &DDTRACE_G(additional_trace_meta));
    zval_copy_ctor(return_value);
}

/* {{{ proto string DDTrace\add_global_tag(string $key, string $value) */
static PHP_FUNCTION(add_global_tag) {
    UNUSED(return_value_used, this_ptr, return_value_ptr);

    char *key, *val;
    int key_len, val_len;
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "ss", &key, &key_len, &val,
                                 &val_len) == FAILURE) {
        ddtrace_log_debug(
            "Unable to parse parameters for DDTrace\\add_global_tag; expected (string $key, string $value)");
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }

    zval *value_zv;
    MAKE_STD_ZVAL(value_zv);
    ZVAL_STRINGL(value_zv, val, val_len, 1);
    zend_hash_update(&DDTRACE_G(additional_global_tags), key, key_len + 1, &value_zv, sizeof(zval *), NULL);

    RETURN_NULL();
}

static PHP_FUNCTION(trace_function) {
    UNUSED(return_value_used, this_ptr, return_value_ptr);
    zval *function = NULL;
    zval *tracing_closure = NULL;
    zval *config_array = NULL;
    uint32_t options = 0;

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "zO", &function, &tracing_closure,
                                 zend_ce_closure) != SUCCESS &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "za", &function, &config_array) !=
            SUCCESS) {
        ddtrace_log_debug("Unexpected parameters, expected (function_name, tracing_closure | config_array)");
        RETURN_BOOL(0);
    }

    if (Z_TYPE_P(function) != IS_STRING) {
        ddtrace_log_debug("function_name must be a string");
        RETURN_BOOL(0);
    }

    if (config_array) {
        if (_parse_config_array(config_array, &tracing_closure, &options TSRMLS_CC) == false) {
            RETURN_BOOL(0);
        }
    } else {
        options |= DDTRACE_DISPATCH_POSTHOOK;
    }

    zend_bool rv = ddtrace_trace(NULL, function, tracing_closure, options TSRMLS_CC);
    RETURN_BOOL(rv);
}

static PHP_FUNCTION(dd_trace_serialize_closed_spans) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    ddtrace_serialize_closed_spans(return_value TSRMLS_CC);
}

// Invoke the function/method from the original context
static PHP_FUNCTION(dd_trace_forward_call) {
    UNUSED(ht, return_value_ptr, this_ptr, return_value_used TSRMLS_CC);
    RETURN_FALSE;
}

static PHP_FUNCTION(dd_trace_env_config) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    char *env_name = NULL;
    int env_name_len;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &env_name, &env_name_len) != SUCCESS) {
        ddtrace_log_debug("Unable to parse parameters for dd_trace_env_config; expected (string $env_name)");
        RETURN_NULL();
    }

    zai_config_id id;
    if (zai_config_get_id_by_name((zai_string_view){.ptr = env_name, .len = env_name_len}, &id)) {
        zval *zv = zai_config_get_value(id);
        RETURN_ZVAL(zv, 1, 0);
    } else {
        RETURN_NULL();
    }
}

// This function allows untracing a function.
static PHP_FUNCTION(dd_untrace) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }

    zval *function = NULL;

    // Remove the traced function from the global lookup
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "z", &function) != SUCCESS) {
        ddtrace_log_debug("unexpected parameter. the function name must be provided");
        RETURN_BOOL(0);
    }

    // Remove the traced function from the global lookup
    if (!function || Z_TYPE_P(function) != IS_STRING) {
        RETURN_BOOL(0);
    }

    if (DDTRACE_G(function_lookup)) {
        zend_hash_del(DDTRACE_G(function_lookup), Z_STRVAL_P(function), Z_STRLEN_P(function) + 1);
    }

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_trace_disable_in_request) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);

    ddtrace_disable_tracing_in_current_request();

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_trace_reset) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }

    ddtrace_dispatch_reset(TSRMLS_C);
    RETURN_BOOL(1);
}

/* {{{ proto string dd_trace_serialize_msgpack(array trace_array) */
static PHP_FUNCTION(dd_trace_serialize_msgpack) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }

    zval *trace_array;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "a", &trace_array) == FAILURE) {
        ddtrace_log_debug("Expected argument to dd_trace_serialize_msgpack() to be an array");
        RETURN_BOOL(0);
    }

    if (ddtrace_serialize_simple_array(trace_array, return_value TSRMLS_CC) != 1) {
        RETURN_BOOL(0);
    }
} /* }}} */

// method used to be able to easily breakpoint the execution at specific PHP line in GDB
static PHP_FUNCTION(dd_trace_noop) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }

    RETURN_BOOL(1);
}

/* {{{ proto int dd_trace_dd_get_memory_limit() */
static PHP_FUNCTION(dd_trace_dd_get_memory_limit) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);

    RETURN_LONG(ddtrace_get_memory_limit(TSRMLS_C));
}

/* {{{ proto bool dd_trace_check_memory_under_limit() */
static PHP_FUNCTION(dd_trace_check_memory_under_limit) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    RETURN_BOOL(ddtrace_is_memory_under_limit(TSRMLS_C));
}

static PHP_FUNCTION(dd_tracer_circuit_breaker_register_error) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);

    dd_tracer_circuit_breaker_register_error();

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_tracer_circuit_breaker_register_success) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);

    dd_tracer_circuit_breaker_register_success();

    RETURN_BOOL(1);
}

static PHP_FUNCTION(dd_tracer_circuit_breaker_can_try) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);

    RETURN_BOOL(dd_tracer_circuit_breaker_can_try());
}

static PHP_FUNCTION(dd_tracer_circuit_breaker_info) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);

    array_init_size(return_value, 5);

    add_assoc_bool(return_value, "closed", dd_tracer_circuit_breaker_is_closed());
    add_assoc_long(return_value, "total_failures", dd_tracer_circuit_breaker_total_failures());
    add_assoc_long(return_value, "consecutive_failures", dd_tracer_circuit_breaker_consecutive_failures());
    add_assoc_long(return_value, "opened_timestamp", dd_tracer_circuit_breaker_opened_timestamp());
    add_assoc_long(return_value, "last_failure_timestamp", dd_tracer_circuit_breaker_last_failure_timestamp());
}

typedef long ddtrace_zpplong_t;

static PHP_FUNCTION(ddtrace_config_app_name) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    char *default_app_name = NULL;
    int default_app_name_len;
    zai_string_view app_name = get_DD_SERVICE();
    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|s", &default_app_name, &default_app_name_len) != SUCCESS) {
        RETURN_NULL();
    }

    if (default_app_name == NULL && app_name.len == 0) {
        RETURN_NULL();
    }

    php_trim(app_name.len ? (char *)app_name.ptr : default_app_name,
             app_name.len ? (int)app_name.len : default_app_name_len, NULL, 0, return_value, 3 TSRMLS_CC);
}

static PHP_FUNCTION(ddtrace_config_distributed_tracing_enabled) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    RETURN_BOOL(get_DD_DISTRIBUTED_TRACING());
}

static PHP_FUNCTION(ddtrace_config_trace_enabled) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    RETURN_BOOL(get_DD_TRACE_ENABLED());
}

static PHP_FUNCTION(ddtrace_config_integration_enabled) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    ddtrace_string name;
    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &name.ptr, &name.len) != SUCCESS) {
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
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    ddtrace_string name;
    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &name.ptr, &name.len) != SUCCESS) {
        RETURN_NULL();
    }
    ddtrace_integration *integration = ddtrace_get_integration_from_string(name);
    if (integration == NULL) {
        RETURN_FALSE;
    }
    RETVAL_BOOL(integration->is_analytics_enabled());
}

static PHP_FUNCTION(integration_analytics_sample_rate) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    ddtrace_string name;
    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &name.ptr, &name.len) != SUCCESS) {
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
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    char *id = ddshared_container_id();
    if (id != NULL && id[0] != '\0') {
        RETVAL_STRING(id, 1);
    } else {
        RETURN_NULL();
    }
}

static PHP_FUNCTION(trigger_error) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    ddtrace_string message;
    ddtrace_zpplong_t error_type;
    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "sl", &message.ptr, &message.len, &error_type) != SUCCESS) {
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
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    if (DDTRACE_G(request_init_hook_loaded) == 1) {
        RETURN_FALSE;
    }

    ddtrace_string dir;
    int ret = 0;
    DDTRACE_G(request_init_hook_loaded) = 1;
    if (get_DD_TRACE_ENABLED() &&
        zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &dir.ptr, &dir.len) == SUCCESS) {
        char *init_file = emalloc(dir.len + sizeof("/dd_init.php"));
        sprintf(init_file, "%s/dd_init.php", dir.ptr);
        ret = dd_execute_php_file(init_file TSRMLS_CC);
        efree(init_file);
    }

    if (DDTRACE_G(auto_prepend_file) && DDTRACE_G(auto_prepend_file)[0]) {
        dd_execute_auto_prepend_file(DDTRACE_G(auto_prepend_file) TSRMLS_CC);
    }
    RETVAL_BOOL(ret);
}

static PHP_FUNCTION(dd_trace_send_traces_via_thread) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    char *payload = NULL;
    ddtrace_zpplong_t num_traces = 0;
    ddtrace_zppstrlen_t payload_len = 0;
    zval *curl_headers = NULL;

    // Agent HTTP headers are now set at the extension level so 'curl_headers' from userland is ignored
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "las", &num_traces, &curl_headers,
                                 &payload, &payload_len) == FAILURE) {
        ddtrace_log_debug("dd_trace_send_traces_via_thread() expects trace count, http headers, and http body");
        RETURN_FALSE;
    }

    bool result = ddtrace_send_traces_via_thread(num_traces, payload, payload_len TSRMLS_CC);
    dd_prepare_for_new_trace(TSRMLS_C);
    RETURN_BOOL(result);
}

static PHP_FUNCTION(dd_trace_buffer_span) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }
    zval *trace_array = NULL;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "a", &trace_array) == FAILURE) {
        ddtrace_log_debug("Expected group id and an array");
        RETURN_BOOL(0);
    }

    char *data;
    size_t size;
    if (ddtrace_serialize_simple_array_into_c_string(trace_array, &data, &size TSRMLS_CC)) {
        RETVAL_BOOL(ddtrace_coms_buffer_data(DDTRACE_G(traces_group_id), data, size));

        free(data);
        return;
    } else {
        RETURN_FALSE;
    }
}

static PHP_FUNCTION(dd_trace_coms_trigger_writer_flush) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);

    RETURN_LONG(ddtrace_coms_trigger_writer_flush());
}

#define FUNCTION_NAME_MATCHES(function) ((sizeof(function) - 1) == fn_len && strncmp(fn, function, fn_len) == 0)

static PHP_FUNCTION(dd_trace_internal_fn) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    zval ***params = NULL;
    uint32_t params_count = 0;

    zval *function_val = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "z*", &function_val, &params, &params_count) != SUCCESS) {
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
            if (ddtrace_serialize_simple_array_into_c_string(trace_array, &data, &size TSRMLS_CC)) {
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
    if (params_count > 0) {
        efree(params);
    }
}

/* {{{ proto string dd_trace_set_trace_id() */
static PHP_FUNCTION(dd_trace_set_trace_id) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);

    zval *trace_id = NULL;
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "|z!", &trace_id) == SUCCESS) {
        if (ddtrace_set_userland_trace_id(trace_id TSRMLS_CC) == true) {
            RETURN_BOOL(1);
        }
    }

    RETURN_BOOL(0);
}

/* {{{ proto string dd_trace_push_span_id() */
static PHP_FUNCTION(dd_trace_push_span_id) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);

    zval *existing_id = NULL;
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "|z!", &existing_id) == SUCCESS) {
        if (ddtrace_push_userland_span_id(existing_id TSRMLS_CC) == true) {
            RETURN_STRING(ddtrace_span_id_as_string(ddtrace_peek_span_id(TSRMLS_C)), 0);
        }
    }

    RETURN_STRING(ddtrace_span_id_as_string(ddtrace_push_span_id(0 TSRMLS_CC)), 0);
}

/* {{{ proto string dd_trace_pop_span_id() */
static PHP_FUNCTION(dd_trace_pop_span_id) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    uint64_t id = ddtrace_pop_span_id(TSRMLS_C);

    if (DDTRACE_G(span_ids_top) == NULL && get_DD_TRACE_AUTO_FLUSH_ENABLED()) {
        if (!ddtrace_flush_tracer(TSRMLS_C)) {
            ddtrace_log_debug("Unable to auto flush the tracer");
        }
    }

    RETURN_STRING(ddtrace_span_id_as_string(id), 0);
}

/* {{{ proto string dd_trace_peek_span_id() */
static PHP_FUNCTION(dd_trace_peek_span_id) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    RETURN_STRING(ddtrace_span_id_as_string(ddtrace_peek_span_id(TSRMLS_C)), 0);
}

/* {{{ proto string DDTrace\active_span() */
static PHP_FUNCTION(active_span) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }
    if (!DDTRACE_G(open_spans_top)) {
        if (get_DD_TRACE_GENERATE_ROOT_SPAN()) {
            ddtrace_push_root_span(
                TSRMLS_C);  // ensure root span always exists, especially after serialization for testing
        } else {
            RETURN_NULL();
        }
    }

    // To be removed once the new hooking mechanism is implemented
    ddtrace_span_fci *span_fci = DDTRACE_G(open_spans_top);
    while (span_fci->span.start == 0 && span_fci->next) {  // skip placeholder span from dd_create_duplicate_span
        span_fci = span_fci->next;
    }

    Z_TYPE_P(return_value) = IS_OBJECT;
    Z_OBJVAL_P(return_value) = span_fci->span.obj_value;
    zend_objects_store_add_ref(return_value TSRMLS_CC);
}

/* {{{ proto string DDTrace\root_span() */
static PHP_FUNCTION(root_span) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }
    if (!DDTRACE_G(root_span)) {
        if (get_DD_TRACE_GENERATE_ROOT_SPAN()) {
            ddtrace_push_root_span(
                TSRMLS_C);  // ensure root span always exists, especially after serialization for testing
        } else {
            RETURN_NULL();
        }
    }
    Z_TYPE_P(return_value) = IS_OBJECT;
    Z_OBJVAL_P(return_value) = DDTRACE_G(root_span)->span.obj_value;
    zend_objects_store_add_ref(return_value TSRMLS_CC);
}

/* {{{ proto string DDTrace\start_span() */
static PHP_FUNCTION(start_span) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    double start_time_seconds = 0;
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "|d", &start_time_seconds) !=
        SUCCESS) {
        ddtrace_log_debug("unexpected parameter. expecting double for start time");
        RETURN_FALSE;
    }
    ddtrace_span_fci *span_fci = ddtrace_init_span(TSRMLS_C);

    Z_TYPE_P(return_value) = IS_OBJECT;
    Z_OBJVAL_P(return_value) = span_fci->span.obj_value;

    if (get_DD_TRACE_ENABLED()) {
        zend_objects_store_add_ref(return_value TSRMLS_CC);
        ddtrace_open_span(span_fci TSRMLS_CC);
    }

    if (start_time_seconds > 0) {
        span_fci->span.start = (uint64_t)(start_time_seconds * 1000000000);
    }
}

/* {{{ proto string DDTrace\close_span() */
static PHP_FUNCTION(close_span) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    double finish_time_seconds = 0;
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "|d", &finish_time_seconds) !=
        SUCCESS) {
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

    ddtrace_close_span(DDTRACE_G(open_spans_top) TSRMLS_CC);
    RETURN_NULL();
}

static PHP_FUNCTION(flush) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    if (!ddtrace_flush_tracer(TSRMLS_C)) {
        ddtrace_log_debug("Unable to flush the tracer");
    }
    RETURN_NULL();
}

/* {{{ proto string \DDTrace\trace_id() */
static PHP_FUNCTION(trace_id) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);
    RETURN_STRING(ddtrace_span_id_as_string(DDTRACE_G(trace_id)), 0);
}

/* {{{ proto array \DDTrace\current_context() */
static PHP_FUNCTION(current_context) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);

    size_t length;
    char buf[DD_TRACE_MAX_ID_LEN + 1];

    array_init(return_value);

    // Add Trace ID
    length = snprintf(buf, sizeof(buf), "%" PRIu64, DDTRACE_G(trace_id));
    add_assoc_stringl_ex(return_value, "trace_id", sizeof("trace_id"), buf, length, 1);

    // Add Span ID
    length = snprintf(buf, sizeof(buf), "%" PRIu64, ddtrace_peek_span_id(TSRMLS_C));
    add_assoc_stringl_ex(return_value, "span_id", sizeof("span_id"), buf, length, 1);

    // Add Version
    zai_string_view version = get_DD_VERSION();
    if (version.len > 0) {
        add_assoc_stringl_ex(return_value, "version", sizeof("version"), (char *)version.ptr, version.len, 1);
    } else {
        add_assoc_null_ex(return_value, "version", sizeof("version"));
    }

    // Add Env
    zai_string_view env = get_DD_ENV();
    if (env.len > 0) {
        add_assoc_stringl_ex(return_value, "env", sizeof("env"), (char *)env.ptr, env.len, 1);
    } else {
        add_assoc_null_ex(return_value, "env", sizeof("env"));
    }

    if (DDTRACE_G(dd_origin)) {
        add_assoc_string_ex(return_value, ZEND_STRS("distributed_tracing_origin"), DDTRACE_G(dd_origin), 1);
    }

    if (DDTRACE_G(distributed_parent_trace_id)) {
        char *parent_id;
        spprintf(&parent_id, DD_TRACE_MAX_ID_LEN, "%" PRIu64, DDTRACE_G(distributed_parent_trace_id));
        add_assoc_string_ex(return_value, ZEND_STRS("distributed_tracing_parent_id"), parent_id, 0);
    }

    zval *tags;
    if (get_DD_TRACE_ENABLED()) {
        tags = ddtrace_get_propagated_tags(TSRMLS_C);
    } else {
        MAKE_STD_ZVAL(tags);
        array_init(tags);
    }
    add_assoc_zval_ex(return_value, ZEND_STRS("distributed_tracing_propagated_tags"), tags);
}

/* {{{ proto bool set_distributed_tracing_context() */
static PHP_FUNCTION(set_distributed_tracing_context) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht);

    char *trace_id_str, *parent_id_str, *origin = NULL;
    int trace_id_len, parent_id_len, origin_len;
    zval *tags = NULL;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "ss|s!z!", &trace_id_str,
                                 &trace_id_len, &parent_id_str, &parent_id_len, &origin, &origin_len,
                                 &tags) != SUCCESS ||
        (tags && Z_TYPE_P(tags) != IS_BOOL && Z_TYPE_P(tags) != IS_NULL && Z_TYPE_P(tags) != IS_ARRAY &&
         Z_TYPE_P(tags) != IS_STRING)) {
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
    ZVAL_STRINGL(&trace_zv, trace_id_str, trace_id_len, 0);
    if (trace_id_len == 1 && trace_id_str[0] == '0') {
        DDTRACE_G(trace_id) = 0;
    } else if (!ddtrace_set_userland_trace_id(&trace_zv TSRMLS_CC)) {
        RETURN_FALSE;
    }

    zval parent_zv;
    ZVAL_STRINGL(&parent_zv, parent_id_str, parent_id_len, 0);
    uint64_t last_id = ddtrace_pop_span_id(TSRMLS_C);
    if (parent_id_len == 1 && parent_id_str[0] == '0') {
        DDTRACE_G(distributed_parent_trace_id) = 0;
    } else if (ddtrace_push_userland_span_id(&parent_zv TSRMLS_CC)) {
        DDTRACE_G(distributed_parent_trace_id) = ddtrace_peek_span_id(TSRMLS_C);
    } else {
        ddtrace_push_span_id(last_id TSRMLS_CC);
        DDTRACE_G(trace_id) = old_trace_id;
        RETURN_FALSE;
    }

    if (origin) {
        if (DDTRACE_G(dd_origin)) {
            efree(DDTRACE_G(dd_origin));
        }
        DDTRACE_G(dd_origin) = origin_len ? estrndup(origin, origin_len) : NULL;
    }

    if (tags) {
        if (Z_TYPE_P(tags) == IS_STRING) {
            ddtrace_add_tracer_tags_from_header(
                (zai_string_view){.len = Z_STRLEN_P(tags), .ptr = Z_STRVAL_P(tags)} TSRMLS_CC);
        } else if (Z_TYPE_P(tags) == IS_ARRAY) {
            ddtrace_add_tracer_tags_from_array(Z_ARRVAL_P(tags) TSRMLS_CC);
        }
    }

    RETURN_TRUE;
}

/* {{{ proto string dd_trace_closed_spans_count() */
static PHP_FUNCTION(dd_trace_closed_spans_count) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    RETURN_LONG(DDTRACE_G(closed_spans_count));
}

bool ddtrace_tracer_is_limited(TSRMLS_D) {
    int64_t limit = get_DD_TRACE_SPANS_LIMIT();
    if (limit >= 0) {
        int64_t open_spans = DDTRACE_G(open_spans_count);
        int64_t closed_spans = DDTRACE_G(closed_spans_count);
        if ((open_spans + closed_spans) >= limit) {
            return true;
        }
    }
    return !ddtrace_is_memory_under_limit(TSRMLS_C);
}

/* {{{ proto string dd_trace_tracer_is_limited() */
static PHP_FUNCTION(dd_trace_tracer_is_limited) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    RETURN_BOOL(ddtrace_tracer_is_limited(TSRMLS_C) == true ? 1 : 0);
}

/* {{{ proto string dd_trace_compile_time_microseconds() */
static PHP_FUNCTION(dd_trace_compile_time_microseconds) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);
    RETURN_LONG(ddtrace_compile_time_get(TSRMLS_C));
}

static PHP_FUNCTION(set_priority_sampling) {
    UNUSED(return_value_used, this_ptr, return_value_ptr);
    bool global = false;
    long priority;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "l|b", &priority, &global) ==
        FAILURE) {
        ddtrace_log_debug("Expected an integer and an optional boolean");
        RETURN_FALSE;
    }

    if (global || !DDTRACE_G(root_span)) {
        DDTRACE_G(default_priority_sampling) = priority;
    } else {
        ddtrace_set_prioritySampling_on_root(priority TSRMLS_CC);
    }
}

static PHP_FUNCTION(get_priority_sampling) {
    UNUSED(return_value_used, this_ptr, return_value_ptr);
    zend_bool global = false;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS() TSRMLS_CC, "|b", &global) == FAILURE) {
        ddtrace_log_debug("Expected an optional boolean");
        RETURN_NULL();
    }

    if (global || !DDTRACE_G(root_span)) {
        RETURN_LONG(DDTRACE_G(default_priority_sampling));
    }

    RETURN_LONG(ddtrace_fetch_prioritySampling_from_root(TSRMLS_C));
}

static PHP_FUNCTION(startup_logs) {
    UNUSED(return_value_used, this_ptr, return_value_ptr, ht TSRMLS_CC);

    smart_str buf = {0};
    ddtrace_startup_logging_json(&buf);
    ZVAL_STRINGL(return_value, buf.c, buf.len, 1);
    smart_str_free(&buf);
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
void dd_prepare_for_new_trace(TSRMLS_D) { DDTRACE_G(traces_group_id) = ddtrace_coms_next_group_id(); }

void dd_read_distributed_tracing_ids(TSRMLS_D) {
    zai_string_view trace_id_str, parent_id_str, dd_origin_str, priority_str, propagated_tags;
    bool success = true;

    if (zai_read_header_literal("X_DATADOG_TRACE_ID", &trace_id_str) == ZAI_HEADER_SUCCESS) {
        if (trace_id_str.len != 1 || trace_id_str.ptr[0] != '0') {
            zval trace_zv;
            ZVAL_STRINGL(&trace_zv, trace_id_str.ptr, trace_id_str.len, 0);
            success = ddtrace_set_userland_trace_id(&trace_zv TSRMLS_CC);
        }
    }

    DDTRACE_G(distributed_parent_trace_id) = 0;
    if (success && zai_read_header_literal("X_DATADOG_PARENT_ID", &parent_id_str) == ZAI_HEADER_SUCCESS) {
        zval parent_zv;
        ZVAL_STRINGL(&parent_zv, parent_id_str.ptr, parent_id_str.len, 0);
        if (parent_id_str.len != 1 || parent_id_str.ptr[0] != '0') {
            if (ddtrace_push_userland_span_id(&parent_zv TSRMLS_CC)) {
                DDTRACE_G(distributed_parent_trace_id) = DDTRACE_G(span_ids_top)->id;
            } else {
                DDTRACE_G(trace_id) = 0;
            }
        }
    }

    DDTRACE_G(dd_origin) = NULL;
    if (zai_read_header_literal("X_DATADOG_ORIGIN", &dd_origin_str) == ZAI_HEADER_SUCCESS) {
        DDTRACE_G(dd_origin) = estrdup(dd_origin_str.ptr);
    }

    if (zai_read_header_literal("X_DATADOG_SAMPLING_PRIORITY", &priority_str) == ZAI_HEADER_SUCCESS) {
        DDTRACE_G(propagated_priority_sampling) = DDTRACE_G(default_priority_sampling) =
            strtol(priority_str.ptr, NULL, 10);
    }

    if (zai_read_header_literal("X_DATADOG_TAGS", &propagated_tags) == ZAI_HEADER_SUCCESS) {
        ddtrace_add_tracer_tags_from_header(propagated_tags TSRMLS_CC);
    }
}
