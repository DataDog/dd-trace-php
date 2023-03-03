#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include <SAPI.h>
#include <Zend/zend.h>
#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_extensions.h>
#include <Zend/zend_smart_str.h>
#include <components/sapi/sapi.h>
#include <headers/headers.h>
#include <hook/hook.h>
#include <json/json.h>
#include <inttypes.h>
#if PHP_VERSION_ID < 80000
#include <Zend/zend_vm.h>
#include <interceptor/php7/interceptor.h>
#else
#include <interceptor/php8/interceptor.h>
#endif
#include <php.h>
#include <php_ini.h>
#include <pthread.h>
#include <stdatomic.h>

#include <ext/standard/info.h>
#include <ext/standard/php_string.h>

#include "auto_flush.h"
#include "circuit_breaker.h"
#include "comms_php.h"
#include "compatibility.h"
#include "coms.h"
#include "config/config.h"
#include "configuration.h"
#include "ddshared.h"
#include "ddtrace.h"
#include "ddtrace_string.h"
#include "dogstatsd_client.h"
#include "engine_hooks.h"
#include "excluded_modules.h"
#include "handlers_http.h"
#include "handlers_internal.h"
#include "integrations/integrations.h"
#include "ip_extraction.h"
#include "logging.h"
#include "memory_limit.h"
#include "limiter/limiter.h"
#include "priority_sampling/priority_sampling.h"
#include "random.h"
#include "request_hooks.h"
#include "serializer.h"
#include "signals.h"
#include "span.h"
#include "startup_logging.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"
#include "ext/standard/file.h"

#include "../hook/uhook.h"
#include "handlers_fiber.h"
#include "handlers_exception.h"
#include "exceptions/exceptions.h"

#include "ddtrace_arginfo.h"

bool ddtrace_has_excluded_module;
static zend_module_entry *ddtrace_module;
#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
static bool dd_has_other_observers;
static int dd_observer_extension_backup = -1;
#endif

atomic_int ddtrace_warn_legacy_api;

ZEND_DECLARE_MODULE_GLOBALS(ddtrace)

#ifdef COMPILE_DL_DDTRACE
ZEND_GET_MODULE(ddtrace)
#ifdef ZTS
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

// put this into startup so that other extensions running code as part of rinit do not crash
static int ddtrace_startup(zend_extension *extension) {
    UNUSED(extension);

    ddtrace_fetch_profiling_symbols();

#if PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400
    // Turns out with zai config we have dynamically allocated INI entries. This does not play well with PHP 7.3
    // As of PHP 7.3 opcache stores INI entry values in SHM. However, only as of PHP 7.4 opcache delays detaching SHM.
    // In PHP 7.3 SHM is freed in MSHUTDOWN, which may be executed before our extension, if we do not force an order.
    // We have to sort this manually here, as opcache only registers itself as extension during zend_extension.startup.
    zend_hash_sort_ex(&module_registry, ddtrace_sort_modules, NULL, 0);
#endif

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
    dd_has_other_observers = ZEND_OBSERVER_ENABLED;
#endif

#if PHP_VERSION_ID < 80000
    zai_interceptor_startup(ddtrace_module);
#else
    zai_interceptor_startup();
#endif

    ddtrace_excluded_modules_startup();
    // We deliberately leave handler replacement during startup, even though this uses some config
    // This touches global state, which, while unlikely, may play badly when interacting with other extensions, if done post-startup
    ddtrace_internal_handlers_startup();
    return SUCCESS;
}

static void ddtrace_shutdown(struct _zend_extension *extension) {
    UNUSED(extension);

    ddtrace_internal_handlers_shutdown();

    zai_interceptor_shutdown();
}

bool dd_save_sampling_rules_file_config(zend_string *path, int modify_type, int stage) {
    if (FG(default_context) == NULL) {
        FG(default_context) = php_stream_context_alloc();
    }
    php_stream_context *context = FG(default_context);
    php_stream *stream = php_stream_open_wrapper_ex(ZSTR_VAL(path), "rb", USE_PATH | REPORT_ERRORS, NULL, context);
    if (!stream) {
        return false;
    }

    zend_string *file = php_stream_copy_to_mem(stream, (ssize_t) PHP_STREAM_COPY_ALL, 0);
    php_stream_close(stream);

    bool altered = false;
    if (file) {
        altered = ZSTR_LEN(file) > 0 && SUCCESS == zend_alter_ini_entry_ex(
            zai_config_memoized_entries[DDTRACE_CONFIG_DD_SPAN_SAMPLING_RULES].ini_entries[0]->name,
            file, modify_type, stage, 1);
        zend_string_release(file);
    }
    return altered;
}

bool ddtrace_alter_sampling_rules_file_config(zval *old_value, zval *new_value) {
    (void) old_value;
    if (Z_STRLEN_P(new_value) == 0) {
        return true;
    }

    return dd_save_sampling_rules_file_config(Z_STR_P(new_value), PHP_INI_USER, PHP_INI_STAGE_RUNTIME);
}

static pthread_once_t dd_activate_config_once_control = PTHREAD_ONCE_INIT;

static void ddtrace_activate(void) {
    zai_hook_rinit();
    zai_interceptor_activate();
    zai_uhook_rinit();
    zend_hash_init(&DDTRACE_G(traced_spans), 8, unused, NULL, 0);
    zend_hash_init(&DDTRACE_G(tracestate_unknown_dd_keys), 8, unused, NULL, 0);

    if (ddtrace_has_excluded_module == true) {
        DDTRACE_G(disable) = 2;
    }

    // ZAI config is always set up
    pthread_once(&dd_activate_config_once_control, ddtrace_config_first_rinit);
    zai_config_rinit();

    zend_string *sampling_rules_file = get_DD_SPAN_SAMPLING_RULES_FILE();
    if (ZSTR_LEN(sampling_rules_file) > 0 && !zend_string_equals(get_global_DD_SPAN_SAMPLING_RULES_FILE(), sampling_rules_file)) {
        dd_save_sampling_rules_file_config(sampling_rules_file, PHP_INI_USER, PHP_INI_STAGE_RUNTIME);
    }

    if (strcmp(sapi_module.name, "cli") == 0 && !get_DD_TRACE_CLI_ENABLED()) {
        DDTRACE_G(disable) = 2;
    }

    if (DDTRACE_G(disable)) {
        ddtrace_disable_tracing_in_current_request();
    }

    DDTRACE_G(request_init_hook_loaded) = 0;

#if PHP_VERSION_ID < 80000
    // This allows us to hook the ZEND_HANDLE_EXCEPTION pseudo opcode
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op));
    EG(exception_op)->opcode = ZEND_HANDLE_EXCEPTION;
#endif
}

static void ddtrace_deactivate(void) {
#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
    if (dd_observer_extension_backup != -1) {
        zend_observer_fcall_op_array_extension = dd_observer_extension_backup;
        dd_observer_extension_backup = -1;
    }
#endif
}

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
#if PHP_VERSION_ID < 80000
                                                  zai_interceptor_op_array_pass_two,
#else
                                                  NULL,
#endif
                                                  NULL,
                                                  NULL,
                                                  NULL,
#if PHP_VERSION_ID < 80000
                                                  zai_interceptor_op_array_ctor,
#else
                                                  NULL,
#endif
                                                  NULL,

                                                  STANDARD_ZEND_EXTENSION_PROPERTIES};

static void php_ddtrace_init_globals(zend_ddtrace_globals *ng) { memset(ng, 0, sizeof(zend_ddtrace_globals)); }

static PHP_GINIT_FUNCTION(ddtrace) {
#if defined(COMPILE_DL_DDTRACE) && defined(ZTS)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    php_ddtrace_init_globals(ddtrace_globals);
}

/* DDTrace\SpanData */
zend_class_entry *ddtrace_ce_span_data;
zend_class_entry *ddtrace_ce_span_stack;
zend_object_handlers ddtrace_span_data_handlers;
zend_object_handlers ddtrace_span_stack_handlers;

static zend_object *ddtrace_span_data_create(zend_class_entry *class_type) {
    ddtrace_span_data *span = ecalloc(1, sizeof(*span));
    zend_object_std_init(&span->std, class_type);
    span->std.handlers = &ddtrace_span_data_handlers;
    array_init(ddtrace_spandata_property_meta_zval(span));
    array_init(ddtrace_spandata_property_metrics_zval(span));
    return &span->std;
}

static zend_object *ddtrace_span_stack_create(zend_class_entry *class_type) {
    ddtrace_span_stack *stack = ecalloc(1, sizeof(*stack));
    zend_object_std_init(&stack->std, class_type);
    stack->root_stack = stack;
    stack->std.handlers = &ddtrace_span_stack_handlers;
    return &stack->std;
}

static void ddtrace_span_stack_dtor_obj(zend_object *object) {
    // We must not invoke span stack destructors during zend_objects_store_call_destructors to avoid them not being present for appsec rshutdown
    if (EG(current_execute_data) == NULL && !DDTRACE_G(in_shutdown)) {
        GC_DEL_FLAGS(object, IS_OBJ_DESTRUCTOR_CALLED);
        return;
    }

    ddtrace_span_stack *stack = (ddtrace_span_stack *)object;
    ddtrace_span_data *top;
    while ((top = stack->active) && top->stack == stack) {
        dd_trace_stop_span_time(top);
        // let's not stack swap to a) avoid side effects in destructors and b) avoid a crash on PHP 7.3 and older
        ddtrace_close_top_span_without_stack_swap(top);
    }
    if (stack->closed_ring || stack->closed_ring_flush) {
        // ensure dtor can be called again
        GC_DEL_FLAGS(object, IS_OBJ_DESTRUCTOR_CALLED);
    }
    zend_objects_destroy_object(object);
}

// span stacks have intrinsic properties (managing other spans, can be switched to), unlike trivial span data which are just value objects
// thus we need to cleanup a little what exactly we can copy
#if PHP_VERSION_ID < 80000
static zend_object *ddtrace_span_stack_clone_obj(zval *old_zv) {
    zend_object *old_obj = Z_OBJ_P(old_zv);
#else
static zend_object *ddtrace_span_stack_clone_obj(zend_object *old_obj) {
#endif
    zend_object *new_obj = ddtrace_span_stack_create(old_obj->ce);
    zend_objects_clone_members(new_obj, old_obj);
    ddtrace_span_stack *stack = (ddtrace_span_stack *)new_obj;
    ddtrace_span_stack *oldstack = (ddtrace_span_stack *)old_obj;
    if (oldstack->parent_stack) { // if this is false, we're copying an initial stack
        stack->root_stack = stack->parent_stack->root_stack;
        stack->root_span = stack->parent_stack->root_span;
    }
    if (oldstack->root_stack == oldstack) {
        stack->root_stack = stack;
    }

    ddtrace_span_data *span = stack->active;
    zval_ptr_dtor(&stack->property_active);
    while (span && span->stack == oldstack) {
        span = span->parent;
    }
    if (span) {
        ZVAL_OBJ_COPY(&stack->property_active, &span->std);
    } else {
        if (oldstack->root_span && oldstack->root_span->stack == oldstack) {
            stack->root_span = NULL;
        }
        stack->active = NULL;
        ZVAL_NULL(&stack->property_active);
    }

    return new_obj;
}

static void ddtrace_span_data_free_storage(zend_object *object) {
    zend_object_std_dtor(object);
    // Prevent use after free after zend_objects_store_free_object_storage is called (e.g. preloading) [PHP < 8.1]
    memset(object->properties_table, 0, sizeof(((ddtrace_span_data *)NULL)->properties_table_placeholder));
}

#if PHP_VERSION_ID < 80000
static zend_object *ddtrace_span_data_clone_obj(zval *old_zv) {
    zend_object *old_obj = Z_OBJ_P(old_zv);
#else
static zend_object *ddtrace_span_data_clone_obj(zend_object *old_obj) {
#endif
    zend_object *new_obj = ddtrace_span_data_create(old_obj->ce);
    zend_objects_clone_members(new_obj, old_obj);
    return new_obj;
}

#if PHP_VERSION_ID < 80000
#if PHP_VERSION_ID >= 70400
static zval *ddtrace_span_data_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#else
static void ddtrace_span_data_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#endif
    zend_object *obj = Z_OBJ_P(object);
    zend_string *prop_name = Z_TYPE_P(member) == IS_STRING ? Z_STR_P(member) : ZSTR_EMPTY_ALLOC();
#else
static zval *ddtrace_span_data_readonly(zend_object *object, zend_string *member, zval *value, void **cache_slot) {
    zend_object *obj = object;
    zend_string *prop_name = member;
#endif
    if (zend_string_equals_literal(prop_name, "parent")
     || zend_string_equals_literal(prop_name, "id")
     || zend_string_equals_literal(prop_name, "stack")) {
        zend_throw_error(zend_ce_error, "Cannot modify readonly property %s::$%s", ZSTR_VAL(obj->ce->name), ZSTR_VAL(prop_name));
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
#if PHP_VERSION_ID < 80000
#if PHP_VERSION_ID >= 70400
static zval *ddtrace_span_stack_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#else
static void ddtrace_span_stack_readonly(zval *object, zval *member, zval *value, void **cache_slot) {
#endif
    zend_object *obj = Z_OBJ_P(object);
    zend_string *prop_name = Z_TYPE_P(member) == IS_STRING ? Z_STR_P(member) : ZSTR_EMPTY_ALLOC();
#else
static zval *ddtrace_span_stack_readonly(zend_object *object, zend_string *member, zval *value, void **cache_slot) {
    zend_object *obj = object;
    zend_string *prop_name = member;
#endif
    if (zend_string_equals_literal(prop_name, "parent")) {
        zend_throw_error(zend_ce_error, "Cannot modify readonly property %s::$%s", ZSTR_VAL(obj->ce->name), ZSTR_VAL(prop_name));
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

PHP_METHOD(DDTrace_SpanData, getDuration) {
    ddtrace_span_data *span = (ddtrace_span_data *)Z_OBJ_P(ZEND_THIS);
    RETURN_LONG(span->duration);
}

PHP_METHOD(DDTrace_SpanData, getStartTime) {
    ddtrace_span_data *span = (ddtrace_span_data *)Z_OBJ_P(ZEND_THIS);
    RETURN_LONG(span->start);
}

static void dd_register_span_data_ce(void) {
    memcpy(&ddtrace_span_data_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    ddtrace_span_data_handlers.clone_obj = ddtrace_span_data_clone_obj;
    ddtrace_span_data_handlers.free_obj = ddtrace_span_data_free_storage;
    ddtrace_span_data_handlers.write_property = ddtrace_span_data_readonly;
#if PHP_VERSION_ID >= 80000
    ddtrace_ce_span_data = register_class_DDTrace_SpanData();
    ddtrace_ce_span_data->create_object = ddtrace_span_data_create;
#else
    (void)(register_class_DDTrace_SpanData); // Unused
    zend_class_entry ce;
    INIT_NS_CLASS_ENTRY(ce, "DDTrace", "SpanData", class_DDTrace_SpanData_methods);
    ddtrace_ce_span_data = zend_register_internal_class(&ce);
    ddtrace_ce_span_data->create_object = ddtrace_span_data_create;

    // trace_id, span_id, parent_id, start & duration are stored directly on
    // ddtrace_span_data so we don't need to make them properties on DDTrace\SpanData
    /*
     * ORDER MATTERS: If you make any changes to the properties below, update the
     * corresponding ddtrace_spandata_property_*() function with the proper offset.
     * ALSO: Update the properties_table_placeholder size of ddtrace_span_data to property count - 1.
     */
    zend_declare_property_null(ddtrace_ce_span_data, "name", sizeof("name") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "resource", sizeof("resource") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "service", sizeof("service") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "type", sizeof("type") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "meta", sizeof("meta") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "metrics", sizeof("metrics") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "exception", sizeof("exception") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "id", sizeof("id") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "parent", sizeof("parent") - 1, ZEND_ACC_PUBLIC);
    zend_declare_property_null(ddtrace_ce_span_data, "stack", sizeof("stack") - 1, ZEND_ACC_PUBLIC);
#endif
    ddtrace_ce_span_stack = register_class_DDTrace_SpanStack();
    ddtrace_ce_span_stack->create_object = ddtrace_span_stack_create;
    memcpy(&ddtrace_span_stack_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    ddtrace_span_stack_handlers.clone_obj = ddtrace_span_stack_clone_obj;
    ddtrace_span_stack_handlers.dtor_obj = ddtrace_span_stack_dtor_obj;
    ddtrace_span_stack_handlers.write_property = ddtrace_span_stack_readonly;

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
#if PHP_VERSION_ID >= 80000
    zai_interceptor_minit();
#endif
#if PHP_VERSION_ID >= 80100
    ddtrace_setup_fiber_observers();
#endif

    register_ddtrace_symbols(module_number);
    REGISTER_INI_ENTRIES();

    zval *ddtrace_module_zv = zend_hash_str_find(&module_registry, ZEND_STRL("ddtrace"));
    if (ddtrace_module_zv) {
        ddtrace_module = Z_PTR_P(ddtrace_module_zv);
    }

    // config initialization needs to be at the top
    if (!ddtrace_config_minit(module_number)) {
        return FAILURE;
    }
    if (ZSTR_LEN(get_global_DD_SPAN_SAMPLING_RULES_FILE()) > 0) {
        dd_save_sampling_rules_file_config(get_global_DD_SPAN_SAMPLING_RULES_FILE(), PHP_INI_SYSTEM, PHP_INI_STAGE_STARTUP);
    }

    dd_disable_if_incompatible_sapi_detected();
    atomic_init(&ddtrace_warn_legacy_api, 1);

    /* This allows an extension (e.g. extension=ddtrace.so) to have zend_engine
     * hooks too, but not loadable as zend_extension=ddtrace.so.
     * See http://www.phpinternalsbook.com/php7/extensions_design/zend_extensions.html#hybrid-extensions
     * {{{ */
    zend_register_extension(&_dd_zend_extension_entry, ddtrace_module_entry.handle);
    // The original entry is copied into the module registry when the module is
    // registered, so we search the module registry to find the right
    // zend_module_entry to modify.
    zend_module_entry *mod_ptr =
        zend_hash_str_find_ptr(&module_registry, PHP_DDTRACE_EXTNAME, sizeof(PHP_DDTRACE_EXTNAME) - 1);
    if (mod_ptr == NULL) {
        // This shouldn't happen, possibly a bug if it does.
        zend_error(E_CORE_WARNING,
                   "Failed to find ddtrace extension in "
                   "registered modules. Please open a bug report.");

        return FAILURE;
    }
    mod_ptr->handle = NULL;
    /* }}} */

    if (DDTRACE_G(disable)) {
        return SUCCESS;
    }

    ddtrace_set_coredumpfilter();

    ddtrace_initialize_span_sampling_limiter();
    ddtrace_limiter_create();

    ddtrace_bgs_log_minit();

    ddtrace_dogstatsd_client_minit();
    ddshared_minit();

    dd_register_span_data_ce();
    dd_register_fatal_error_ce();

    ddtrace_engine_hooks_minit();

    ddtrace_coms_minit(get_global_DD_TRACE_AGENT_STACK_INITIAL_SIZE(),
                       get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE(),
                       get_global_DD_TRACE_AGENT_STACK_BACKLOG());

    ddtrace_integrations_minit();
    dd_ip_extraction_startup();

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

    ddtrace_shutdown_span_sampling_limiter();
    ddtrace_limiter_destroy();
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

static pthread_once_t dd_rinit_once_control = PTHREAD_ONCE_INIT;

static void dd_initialize_request(void) {
    DDTRACE_G(additional_global_tags) = zend_new_array(0);
    DDTRACE_G(default_priority_sampling) = DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
    DDTRACE_G(propagated_priority_sampling) = DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
    zend_hash_init(&DDTRACE_G(root_span_tags_preset), 8, unused, ZVAL_PTR_DTOR, 0);
    zend_hash_init(&DDTRACE_G(propagated_root_span_tags), 8, unused, ZVAL_PTR_DTOR, 0);
    zend_hash_init(&DDTRACE_G(tracestate_unknown_dd_keys), 8, unused, ZVAL_PTR_DTOR, 0);

    // Things that should only run on the first RINIT
    pthread_once(&dd_rinit_once_control, dd_rinit_once);

    if (ZSTR_LEN(get_DD_TRACE_REQUEST_INIT_HOOK())) {
        dd_request_init_hook_rinit();
    }

    ddtrace_internal_handlers_rinit();
    ddtrace_bgs_log_rinit(PG(error_log));

    ddtrace_dogstatsd_client_rinit();

    ddtrace_seed_prng();
    ddtrace_init_span_stacks();
    ddtrace_coms_on_pid_change();

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

#if PHP_VERSION_ID < 80000
    zai_interceptor_rinit();
#endif

    if (!DDTRACE_G(disable)) {
        // With internal functions also being hookable, they must not be hooked before the CG(map_ptr_base) is zeroed
        zai_hook_activate();
        DDTRACE_G(active_stack) = ddtrace_init_root_span_stack();
    }

    if (get_DD_TRACE_ENABLED()) {
        dd_initialize_request();
    }

    return SUCCESS;
}

static void dd_clean_globals(void) {
    zend_array_destroy(DDTRACE_G(additional_global_tags));
    zend_hash_destroy(&DDTRACE_G(root_span_tags_preset));
    zend_hash_destroy(&DDTRACE_G(tracestate_unknown_dd_keys));
    zend_hash_destroy(&DDTRACE_G(propagated_root_span_tags));

    if (DDTRACE_G(dd_origin)) {
        zend_string_release(DDTRACE_G(dd_origin));
    }

    if (DDTRACE_G(tracestate)) {
        zend_string_release(DDTRACE_G(tracestate));
    }

    ddtrace_internal_handlers_rshutdown();
    ddtrace_dogstatsd_client_rshutdown();

    ddtrace_free_span_stacks(false);
    ddtrace_coms_rshutdown();

    if (ZSTR_LEN(get_DD_TRACE_REQUEST_INIT_HOOK())) {
        dd_request_init_hook_rshutdown();
    }
}

static void dd_shutdown_hooks_and_observer(void) {
    zai_hook_clean();

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
#if PHP_VERSION_ID < 80100
#define RUN_TIME_CACHE_OBSERVER_PATCH_VERSION 18
#else
#define RUN_TIME_CACHE_OBSERVER_PATCH_VERSION 4
#endif

    zend_long patch_version = Z_LVAL_P(zend_get_constant_str(ZEND_STRL("PHP_RELEASE_VERSION")));
    if (patch_version < RUN_TIME_CACHE_OBSERVER_PATCH_VERSION) {
        // Just do it if we think to be the only observer ... don't want to break other functionalities
        if (!dd_has_other_observers) {
            // We really should not have to do it. But there is a bug before PHP 8.0.18 and 8.1.4 respectively, causing observer fcall info being freed before stream shutdown (which may still invoke user code)
            dd_observer_extension_backup = zend_observer_fcall_op_array_extension;
            zend_observer_fcall_op_array_extension = -1;
        }
    }
#endif
}

void dd_force_shutdown_tracing(void) {
    DDTRACE_G(in_shutdown) = true;

    ddtrace_close_all_open_spans(true);  // All remaining userland spans (and root span)
    if (ddtrace_flush_tracer(false) == FAILURE) {
        ddtrace_log_debug("Unable to flush the tracer");
    }

    // we here need to disable the tracer, so that further hooks do not trigger
    ddtrace_disable_tracing_in_current_request();  // implicitly calling dd_clean_globals

    // The hooks shall not be reset, just disabled at runtime.
    dd_shutdown_hooks_and_observer();

    DDTRACE_G(in_shutdown) = false;
}

static PHP_RSHUTDOWN_FUNCTION(ddtrace) {
    UNUSED(module_number, type);

    zend_hash_destroy(&DDTRACE_G(traced_spans));

    if (get_DD_TRACE_ENABLED()) {
        dd_force_shutdown_tracing();
    } else if (!DDTRACE_G(disable)) {
        dd_shutdown_hooks_and_observer();
    }

    if (!DDTRACE_G(disable)) {
        OBJ_RELEASE(&DDTRACE_G(active_stack)->std);
        DDTRACE_G(active_stack) = NULL;
    }

    return SUCCESS;
}

#if PHP_VERSION_ID < 80000
int ddtrace_post_deactivate(void) {
#else
zend_result ddtrace_post_deactivate(void) {
#endif
    zai_interceptor_deactivate();

    // we can only actually free our hooks hashtables in post_deactivate, as within RSHUTDOWN some user code may still run
    zai_hook_rshutdown();
    zai_uhook_rshutdown();

    // zai config may be accessed indirectly via other modules RSHUTDOWN, so delay this until the last possible time
    zai_config_rshutdown();
    return SUCCESS;
}

void ddtrace_disable_tracing_in_current_request(void) {
    // PHP 8 has ZSTR_CHAR('0') which is nicer...
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
        ddtrace_close_all_open_spans(false);  // All remaining userland spans (and root span)
        dd_clean_globals();
    }

    return true;
}

static size_t datadog_info_print(const char *str) { return php_output_write(str, strlen(str)); }

static void _dd_info_tracer_config(void) {
    smart_str buf = {0};
    ddtrace_startup_logging_json(&buf, PHP_JSON_PRETTY_PRINT);
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

static bool ddtrace_should_warn_legacy(void) {
    int expected = 1;
    return atomic_compare_exchange_strong(&ddtrace_warn_legacy_api, &expected, 0) &&
           get_DD_TRACE_WARN_LEGACY_DD_TRACE();
}

PHP_FUNCTION(dd_trace) {
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

// legacy function
PHP_FUNCTION(additional_trace_meta) {
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "")) {
        ddtrace_log_debug("Unexpected parameters to DDTrace\\additional_trace_meta");
    }
    array_init(return_value);
}

/* {{{ proto string DDTrace\add_global_tag(string $key, string $value) */
PHP_FUNCTION(DDTrace_add_global_tag) {
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

/* {{{ proto string DDTrace\add_distributed_tag(string $key, string $value) */
PHP_FUNCTION(DDTrace_add_distributed_tag) {
    UNUSED(execute_data);

    zend_string *key, *val;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "SS", &key, &val) == FAILURE) {
        ddtrace_log_debug(
            "Unable to parse parameters for DDTrace\\add_distributed_tag; expected (string $key, string $value)");
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }

    zend_string *prefixed_key = zend_strpprintf(0, "_dd.p.%s", ZSTR_VAL(key));

    zend_array *target_table;
    if (DDTRACE_G(active_stack)->root_span) {
        target_table = ddtrace_spandata_property_meta(DDTRACE_G(active_stack)->root_span);
    } else {
        target_table = &DDTRACE_G(root_span_tags_preset);
    }

    zval value_zv;
    ZVAL_STR_COPY(&value_zv, val);
    zend_hash_update(target_table, prefixed_key, &value_zv);

    zend_hash_add_empty_element(&DDTRACE_G(propagated_root_span_tags), prefixed_key);

    zend_string_release(prefixed_key);

    RETURN_NULL();
}

PHP_FUNCTION(DDTrace_set_user) {
    UNUSED(execute_data);

    zend_string *user_id;
    HashTable *metadata = NULL;
    zend_bool propagate = get_DD_TRACE_PROPAGATE_USER_ID_DEFAULT();
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S|hb", &user_id, &metadata,
      &propagate) == FAILURE) {
        ddtrace_log_debug("Unexpected parameter combination, expected "
                          "(user_id, metadata, propagate)");
        RETURN_NULL();
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }

    if (user_id == NULL || ZSTR_LEN(user_id) == 0) {
        ddtrace_log_debug("Unexpected empty user id");
        RETURN_NULL();
    }

    zend_array *target_table;
    if (DDTRACE_G(active_stack)->root_span) {
        target_table = ddtrace_spandata_property_meta(DDTRACE_G(active_stack)->root_span);
    } else {
        target_table = &DDTRACE_G(root_span_tags_preset);
    }

    zval user_id_zv;
    ZVAL_STR_COPY(&user_id_zv, user_id);
    zend_hash_str_update(target_table, ZEND_STRL("usr.id"), &user_id_zv);

    if (propagate) {
        zval value_zv;
        zend_string *encoded_user_id = php_base64_encode_str(user_id);
        ZVAL_STR(&value_zv, encoded_user_id);
        zend_hash_str_update(target_table, ZEND_STRL("_dd.p.usr.id"), &value_zv);

        zend_hash_str_add_empty_element(&DDTRACE_G(propagated_root_span_tags),
            ZEND_STRL("_dd.p.usr.id"));
    }

    if (metadata != NULL) {
        zend_string *key;
        zval *value;
        ZEND_HASH_FOREACH_STR_KEY_VAL(metadata, key, value)
        {
            if (!key || Z_TYPE_P(value) != IS_STRING) {
                continue;
            }

            zend_string *prefixed_key = zend_strpprintf(0, "usr.%s", ZSTR_VAL(key));

            zval value_copy;
            ZVAL_COPY(&value_copy, value);
            zend_hash_update(target_table, prefixed_key, &value_copy);

            zend_string_release(prefixed_key);
        }
        ZEND_HASH_FOREACH_END();
    }

    RETURN_NULL();
}

PHP_FUNCTION(dd_trace_serialize_closed_spans) {
    UNUSED(execute_data);
    if (!get_DD_TRACE_ENABLED()) {
        array_init(return_value);
        return;
    }

    ddtrace_mark_all_span_stacks_flushable();

    ddtrace_serialize_closed_spans(return_value);

    ddtrace_free_span_stacks(false);
    ddtrace_init_span_stacks();
}

// Invoke the function/method from the original context
PHP_FUNCTION(dd_trace_forward_call) {
    UNUSED(execute_data);
    RETURN_FALSE;
}

PHP_FUNCTION(dd_trace_env_config) {
    UNUSED(execute_data);
    zend_string *env_name;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &env_name) == FAILURE) {
        ddtrace_log_debug("Unable to parse parameters for dd_trace_env_config; expected (string $env_name)");
        RETURN_NULL();
    }

    zai_config_id id;
    if (zai_config_get_id_by_name((zai_string_view){.ptr = ZSTR_VAL(env_name), .len = ZSTR_LEN(env_name)}, &id)) {
        RETURN_COPY(zai_config_get_value(id));
    } else {
        RETURN_NULL();
    }
}

PHP_FUNCTION(dd_trace_disable_in_request) {
    UNUSED(execute_data);

    ddtrace_disable_tracing_in_current_request();

    RETURN_BOOL(1);
}

PHP_FUNCTION(dd_trace_reset) {
    UNUSED(execute_data);
    if (DDTRACE_G(disable)) {
        RETURN_BOOL(0);
    }

    // TODO ??
    RETURN_BOOL(1);
}

/* {{{ proto string dd_trace_serialize_msgpack(array trace_array) */
PHP_FUNCTION(dd_trace_serialize_msgpack) {
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
PHP_FUNCTION(dd_trace_noop) {
    UNUSED(execute_data);

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_BOOL(0);
    }

    RETURN_BOOL(1);
}

/* {{{ proto int dd_trace_dd_get_memory_limit() */
PHP_FUNCTION(dd_trace_dd_get_memory_limit) {
    UNUSED(execute_data);

    RETURN_LONG(ddtrace_get_memory_limit());
}

/* {{{ proto bool dd_trace_check_memory_under_limit() */
PHP_FUNCTION(dd_trace_check_memory_under_limit) {
    UNUSED(execute_data);
    RETURN_BOOL(ddtrace_is_memory_under_limit());
}

PHP_FUNCTION(dd_tracer_circuit_breaker_register_error) {
    UNUSED(execute_data);

    dd_tracer_circuit_breaker_register_error();

    RETURN_BOOL(1);
}

PHP_FUNCTION(dd_tracer_circuit_breaker_register_success) {
    UNUSED(execute_data);

    dd_tracer_circuit_breaker_register_success();

    RETURN_BOOL(1);
}

PHP_FUNCTION(dd_tracer_circuit_breaker_can_try) {
    UNUSED(execute_data);

    RETURN_BOOL(dd_tracer_circuit_breaker_can_try());
}

PHP_FUNCTION(dd_tracer_circuit_breaker_info) {
    UNUSED(execute_data);

    array_init_size(return_value, 5);

    add_assoc_bool(return_value, "closed", dd_tracer_circuit_breaker_is_closed());
    add_assoc_long(return_value, "total_failures", dd_tracer_circuit_breaker_total_failures());
    add_assoc_long(return_value, "consecutive_failures", dd_tracer_circuit_breaker_consecutive_failures());
    add_assoc_long(return_value, "opened_timestamp", (zend_long)dd_tracer_circuit_breaker_opened_timestamp());
    add_assoc_long(return_value, "last_failure_timestamp", (zend_long)dd_tracer_circuit_breaker_last_failure_timestamp());
}

typedef zend_long ddtrace_zpplong_t;

PHP_FUNCTION(ddtrace_config_app_name) {
    zend_string *default_app_name = NULL, *app_name = get_DD_SERVICE();
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|S", &default_app_name) != SUCCESS) {
        RETURN_NULL();
    }

    if (default_app_name == NULL && ZSTR_LEN(app_name) == 0) {
        RETURN_NULL();
    }

    RETURN_STR(php_trim(ZSTR_LEN(app_name) ? app_name : default_app_name, NULL, 0, 3));
}

PHP_FUNCTION(ddtrace_config_distributed_tracing_enabled) {
    UNUSED(execute_data);
    RETURN_BOOL(get_DD_DISTRIBUTED_TRACING());
}

PHP_FUNCTION(ddtrace_config_trace_enabled) {
    UNUSED(execute_data);
    RETURN_BOOL(get_DD_TRACE_ENABLED());
}

PHP_FUNCTION(ddtrace_config_integration_enabled) {
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

PHP_FUNCTION(DDTrace_Config_integration_analytics_enabled) {
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

PHP_FUNCTION(DDTrace_Config_integration_analytics_sample_rate) {
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
PHP_FUNCTION(DDTrace_System_container_id) {
    UNUSED(execute_data);
    char *id = ddshared_container_id();
    if (id != NULL && id[0] != '\0') {
        RETVAL_STRING(id);
    } else {
        RETURN_NULL();
    }
}

PHP_FUNCTION(DDTrace_Testing_trigger_error) {
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

PHP_FUNCTION(ddtrace_init) {
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

PHP_FUNCTION(dd_trace_send_traces_via_thread) {
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

PHP_FUNCTION(dd_trace_buffer_span) {
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

PHP_FUNCTION(dd_trace_coms_trigger_writer_flush) {
    UNUSED(execute_data);

    RETURN_LONG(ddtrace_coms_trigger_writer_flush());
}

#define FUNCTION_NAME_MATCHES(function) ((sizeof(function) - 1) == fn_len && strncmp(fn, function, fn_len) == 0)

PHP_FUNCTION(dd_trace_internal_fn) {
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

/* {{{ proto int DDTrace\close_spans_until(DDTrace\SpanData) */
PHP_FUNCTION(DDTrace_close_spans_until) {
    zval *untilzv = NULL;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "O!", &untilzv, ddtrace_ce_span_data) ==
        FAILURE) {
        ddtrace_log_debug("DDTrace\\close_spans_until() expects null or a SpanData object");
        RETURN_FALSE;
    }

    int closed_spans = ddtrace_close_userland_spans_until(untilzv ? (ddtrace_span_data *)Z_OBJ_P(untilzv) : NULL);

    if (closed_spans == -1) {
        RETURN_FALSE;
    }
    RETURN_LONG(closed_spans);
}

/* {{{ proto string dd_trace_set_trace_id() */
PHP_FUNCTION(dd_trace_set_trace_id) {
    UNUSED(execute_data);

    zval *trace_id = NULL;
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "|z!", &trace_id) == SUCCESS) {
        if (trace_id && Z_TYPE_P(trace_id) == IS_STRING) {
            ddtrace_trace_id new_trace_id = ddtrace_parse_userland_trace_id(trace_id);
            if (new_trace_id.low || new_trace_id.high || (Z_STRLEN_P(trace_id) == 1 && Z_STRVAL_P(trace_id)[0] == '0')) {
                DDTRACE_G(distributed_trace_id) = new_trace_id;
                RETURN_TRUE;
            }
        }
    }

    RETURN_FALSE;
}

atomic_int ddtrace_warn_span_id_legacy_api = 1;
static void ddtrace_warn_span_id_legacy(void) {
    int expected = 1;
    if (atomic_compare_exchange_strong(&ddtrace_warn_span_id_legacy_api, &expected, 0) &&
        get_DD_TRACE_WARN_LEGACY_DD_TRACE()) {
        ddtrace_log_err(
            "dd_trace_push_span_id and dd_trace_pop_span_id DEPRECATION NOTICE: the functions `dd_trace_push_span_id` and `dd_trace_pop_span_id` are deprecated and have become a no-op since 0.74.0, and will eventually be removed. To create or pop spans use `DDTrace\\start_span` and `DDTrace\\close_span` respectively. To set a distributed parent trace context use `DDTrace\\set_distributed_tracing_context`. Set DD_TRACE_WARN_LEGACY_DD_TRACE=0 to suppress this warning.");
    }
}

/* {{{ proto string dd_trace_push_span_id() */
PHP_FUNCTION(dd_trace_push_span_id) {
    UNUSED(execute_data);
    ddtrace_warn_span_id_legacy();
    RETURN_STRING("0");
}

/* {{{ proto string dd_trace_pop_span_id() */
PHP_FUNCTION(dd_trace_pop_span_id) {
    UNUSED(execute_data);
    ddtrace_warn_span_id_legacy();
    RETURN_STRING("0");
}

/* {{{ proto string dd_trace_peek_span_id() */
PHP_FUNCTION(dd_trace_peek_span_id) {
    UNUSED(execute_data);
    RETURN_STR(ddtrace_span_id_as_string(ddtrace_peek_span_id()));
}

static void dd_ensure_root_span(void) {
    if (!DDTRACE_G(active_stack)->root_span && DDTRACE_G(active_stack)->parent_stack == NULL && get_DD_TRACE_GENERATE_ROOT_SPAN()) {
        ddtrace_push_root_span();  // ensure root span always exists, especially after serialization for testing
    }
}

/* {{{ proto string DDTrace\active_span() */
PHP_FUNCTION(DDTrace_active_span) {
    UNUSED(execute_data);
    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }
    dd_ensure_root_span();
    ddtrace_span_data *span = ddtrace_active_span();
    if (span) {
        RETURN_OBJ_COPY(&span->std);
    }
    RETURN_NULL();
}

/* {{{ proto string DDTrace\root_span() */
PHP_FUNCTION(DDTrace_root_span) {
    UNUSED(execute_data);
    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }
    dd_ensure_root_span();
    ddtrace_span_data *span = DDTRACE_G(active_stack)->root_span;
    if (span) {
        RETURN_OBJ_COPY(&span->std);
    }
    RETURN_NULL();
}

static inline void dd_start_span(INTERNAL_FUNCTION_PARAMETERS) {
    double start_time_seconds = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|d", &start_time_seconds) != SUCCESS) {
        ddtrace_log_debug("unexpected parameter. expecting double for start time");
        RETURN_FALSE;
    }

    ddtrace_span_data *span = ddtrace_init_span(DDTRACE_USER_SPAN);

    if (get_DD_TRACE_ENABLED()) {
        ddtrace_open_span(span);
    }

    if (start_time_seconds > 0) {
        span->start = (uint64_t)(start_time_seconds * 1000000000);
    }

    RETURN_OBJ(&span->std);
}

/* {{{ proto string DDTrace\start_span() */
PHP_FUNCTION(DDTrace_start_span) {
    dd_start_span(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

/* {{{ proto string DDTrace\start_trace_span() */
PHP_FUNCTION(DDTrace_start_trace_span) {
    if (get_DD_TRACE_ENABLED()) {
        ddtrace_span_stack *stack = ddtrace_init_root_span_stack();
        ddtrace_switch_span_stack(stack);
        GC_DELREF(&stack->std); // We don't retain a ref to it, it's now the active_stack
    }
    dd_start_span(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

/* {{{ proto string DDTrace\close_span() */
PHP_FUNCTION(DDTrace_close_span) {
    double finish_time_seconds = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|d", &finish_time_seconds) != SUCCESS) {
        ddtrace_log_debug("unexpected parameter. expecting double for finish time");
        RETURN_FALSE;
    }

    ddtrace_span_data *top_span = ddtrace_active_span();

    if (!top_span || top_span->type != DDTRACE_USER_SPAN) {
        ddtrace_log_err("There is no user-span on the top of the stack. Cannot close.");
        RETURN_NULL();
    }

    // we do not expose the monotonic time here, so do not use it as reference time to calculate difference
    uint64_t start_time = top_span->start;
    uint64_t finish_time = (uint64_t)(finish_time_seconds * 1000000000);
    if (finish_time < start_time) {
        dd_trace_stop_span_time(top_span);
    } else {
        top_span->duration = finish_time - start_time;
    }

    ddtrace_close_span(top_span);
    RETURN_NULL();
}

/* {{{ proto string DDTrace\active_stack() */
PHP_FUNCTION(DDTrace_active_stack) {
    UNUSED(execute_data);
    if (!DDTRACE_G(active_stack)) {
        RETURN_NULL();
    }
    RETURN_OBJ_COPY(&DDTRACE_G(active_stack)->std);
}

/* {{{ proto string DDTrace\create_stack() */
PHP_FUNCTION(DDTrace_create_stack) {
    UNUSED(execute_data);

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_OBJ(&ddtrace_init_root_span_stack()->std);
    }

    ddtrace_span_stack *stack = ddtrace_init_span_stack();
    ddtrace_switch_span_stack(stack);
    RETURN_OBJ(&stack->std);
}

/* {{{ proto string DDTrace\switch_stack(DDTrace\SpanData|DDTrace\SpanStack) */
PHP_FUNCTION(DDTrace_switch_stack) {
    zval *objzv = NULL;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|o", &objzv) != SUCCESS
    || (objzv && Z_OBJCE_P(objzv) != ddtrace_ce_span_data && Z_OBJCE_P(objzv) != ddtrace_ce_span_stack)) {
        ddtrace_log_debug("unexpected parameter. expecting SpanData or SpanStack");
        RETURN_FALSE;
    }

    if (!DDTRACE_G(active_stack)) {
        RETURN_NULL();
    }

    if (objzv) {
        zend_object *obj = Z_OBJ_P(objzv);
        ddtrace_switch_span_stack(obj->ce == ddtrace_ce_span_data ? ((ddtrace_span_data *) obj)->stack : (ddtrace_span_stack *) obj);
    } else if (DDTRACE_G(active_stack)->parent_stack) {
        ddtrace_switch_span_stack(DDTRACE_G(active_stack)->parent_stack);
    }

    RETURN_OBJ_COPY(&DDTRACE_G(active_stack)->std);
}

PHP_FUNCTION(DDTrace_flush) {
    UNUSED(execute_data);
    if (get_DD_AUTOFINISH_SPANS()) {
        ddtrace_close_userland_spans_until(NULL);
    }
    if (ddtrace_flush_tracer(false) == FAILURE) {
        ddtrace_log_debug("Unable to flush the tracer");
    }
    RETURN_NULL();
}

/* {{{ proto string \DDTrace\trace_id() */
PHP_FUNCTION(DDTrace_trace_id) {
    UNUSED(execute_data);
    RETURN_STR(ddtrace_trace_id_as_string(ddtrace_peek_trace_id()));
}

/* {{{ proto array \DDTrace\current_context() */
PHP_FUNCTION(DDTrace_current_context) {
    UNUSED(execute_data);

    array_init(return_value);

    add_assoc_str_ex(return_value, ZEND_STRL("trace_id"), ddtrace_trace_id_as_string(ddtrace_peek_trace_id()));
    add_assoc_str_ex(return_value, ZEND_STRL("span_id"), ddtrace_span_id_as_string(ddtrace_peek_span_id()));

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
        add_assoc_str_ex(return_value, ZEND_STRL("distributed_tracing_parent_id"), ddtrace_span_id_as_string(DDTRACE_G(distributed_parent_trace_id)));
    }

    zval tags;
    array_init(&tags);
    if (get_DD_TRACE_ENABLED()) {
        ddtrace_get_propagated_tags(Z_ARR(tags));
    }
    add_assoc_zval_ex(return_value, ZEND_STRL("distributed_tracing_propagated_tags"), &tags);
}

static void dd_clear_propagated_tags_from_root_span(void) {
    if (DDTRACE_G(active_stack)->root_span) {
        zend_string *tagname;
        zend_array *meta = ddtrace_spandata_property_meta(DDTRACE_G(active_stack)->root_span);
        ZEND_HASH_FOREACH_STR_KEY(&DDTRACE_G(propagated_root_span_tags), tagname) { zend_hash_del(meta, tagname); }
        ZEND_HASH_FOREACH_END();
    }
}

static void dd_apply_propagated_values_to_existing_spans(void) {
    ddtrace_span_data *span = ddtrace_active_span();
    while (span) {
        zend_array *meta = ddtrace_spandata_property_meta(span);
        if (!DDTRACE_G(distributed_trace_id).low && !DDTRACE_G(distributed_trace_id).high) {
            span->trace_id = (ddtrace_trace_id) {
                .low = span->root->span_id,
                .time = get_DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED() ? span->start / UINT64_C(1000000000) : 0,
            };
        } else {
            span->trace_id = DDTRACE_G(distributed_trace_id);
        }

        if (DDTRACE_G(dd_origin)) {
            zval value;
            ZVAL_STR_COPY(&value, DDTRACE_G(dd_origin));
            zend_hash_str_update(meta, ZEND_STRL("_dd.origin"), &value);
        } else {
            zend_hash_str_del(meta, ZEND_STRL("_dd.origin"));
        }

        span = span->parent;
    }

    if (DDTRACE_G(active_stack)->root_span) {
        DDTRACE_G(active_stack)->root_span->parent_id = DDTRACE_G(distributed_parent_trace_id);
    }
}

/* {{{ proto bool set_distributed_tracing_context() */
PHP_FUNCTION(DDTrace_set_distributed_tracing_context) {
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

    ddtrace_trace_id old_trace_id = DDTRACE_G(distributed_trace_id), new_trace_id;
    zval trace_zv;
    ZVAL_STR(&trace_zv, trace_id_str);
    if (ZSTR_LEN(trace_id_str) == 1 && ZSTR_VAL(trace_id_str)[0] == '0') {
        DDTRACE_G(distributed_trace_id) = (ddtrace_trace_id){ 0 };
    } else if ((new_trace_id = ddtrace_parse_userland_trace_id(&trace_zv)).low || new_trace_id.high) {
        DDTRACE_G(distributed_trace_id) = new_trace_id;
    } else {
        RETURN_FALSE;
    }

    zval parent_zv;
    ZVAL_STR(&parent_zv, parent_id_str);
    uint64_t new_parent_id;
    if (ZSTR_LEN(parent_id_str) == 1 && ZSTR_VAL(parent_id_str)[0] == '0') {
        DDTRACE_G(distributed_parent_trace_id) = 0;
    } else if ((new_parent_id = ddtrace_parse_userland_span_id(&parent_zv))) {
        DDTRACE_G(distributed_parent_trace_id) = new_parent_id;
    } else {
        DDTRACE_G(distributed_trace_id) = old_trace_id;
        RETURN_FALSE;
    }

    if (origin) {
        if (DDTRACE_G(dd_origin)) {
            zend_string_release(DDTRACE_G(dd_origin));
        }
        DDTRACE_G(dd_origin) = ZSTR_LEN(origin) ? zend_string_copy(origin) : NULL;
    }

    if (tags) {
        dd_clear_propagated_tags_from_root_span();

        if (Z_TYPE_P(tags) == IS_STRING) {
            ddtrace_add_tracer_tags_from_header(Z_STR_P(tags));
        } else if (Z_TYPE_P(tags) == IS_ARRAY) {
            ddtrace_add_tracer_tags_from_array(Z_ARR_P(tags));
        }

        if (DDTRACE_G(active_stack)->root_span) {
            ddtrace_get_propagated_tags(ddtrace_spandata_property_meta(DDTRACE_G(active_stack)->root_span));
        }
    }

    dd_apply_propagated_values_to_existing_spans();

    RETURN_TRUE;
}

void ddtrace_read_distributed_tracing_ids(bool (*read_header)(zai_string_view, const char *, zend_string **header_value, void *data), void *data);

typedef struct {
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
} dd_fci_fcc_pair;

static bool dd_read_userspace_header(zai_string_view zai_header, const char *lowercase_header, zend_string **header_value, void *data) {
    UNUSED(zai_header);
    dd_fci_fcc_pair *func = (dd_fci_fcc_pair *) data;
    zval retval, arg;
    func->fci.params = &arg;
    ZVAL_STRING(&arg, lowercase_header);

    if (zend_call_function_with_return_value(&func->fci, &func->fcc, &retval) != SUCCESS || Z_TYPE(retval) <= IS_NULL) {
        zval_ptr_dtor(&arg);
        return false;
    }

    *header_value = zval_get_string(&retval);

    zval_ptr_dtor(&arg);
    zval_ptr_dtor(&retval);

    return true;
}

PHP_FUNCTION(DDTrace_consume_distributed_tracing_headers) {
    UNUSED(return_value);
    dd_fci_fcc_pair func;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_FUNC(func.fci, func.fcc)
    ZEND_PARSE_PARAMETERS_END();

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_NULL();
    }

    func.fci.param_count = 1;

    if (DDTRACE_G(dd_origin)) {
        zend_string_release(DDTRACE_G(dd_origin));
    }
    if (DDTRACE_G(tracestate)) {
        zend_string_release(DDTRACE_G(tracestate));
    }
    dd_clear_propagated_tags_from_root_span();

    ddtrace_read_distributed_tracing_ids(dd_read_userspace_header, &func);

    if (DDTRACE_G(active_stack)->root_span) {
        ddtrace_get_propagated_tags(ddtrace_spandata_property_meta(DDTRACE_G(active_stack)->root_span));
    }
    dd_apply_propagated_values_to_existing_spans();

    RETURN_NULL();
}

/* {{{ proto array generate_distributed_tracing_headers() */
PHP_FUNCTION(DDTrace_generate_distributed_tracing_headers) {
    zend_array *inject = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT(inject)
    ZEND_PARSE_PARAMETERS_END();

    array_init(return_value);
    if (get_DD_TRACE_ENABLED()) {
        if (inject) {
            zend_array *inject_set = zend_new_array(zend_hash_num_elements(inject));
            zval *val;
            ZEND_HASH_FOREACH_VAL(inject, val) {
                if (Z_TYPE_P(val) == IS_STRING) {
                    zend_hash_add_empty_element(inject_set, Z_STR_P(val));
                }
            } ZEND_HASH_FOREACH_END();
            ddtrace_inject_distributed_headers_config(Z_ARR_P(return_value), true, inject_set);
            zend_array_destroy(inject_set);
        } else {
            ddtrace_inject_distributed_headers(Z_ARR_P(return_value), true);
        }
    }
}

/* {{{ proto string dd_trace_closed_spans_count() */
PHP_FUNCTION(dd_trace_closed_spans_count) {
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
PHP_FUNCTION(dd_trace_tracer_is_limited) {
    UNUSED(execute_data);
    RETURN_BOOL(ddtrace_tracer_is_limited() == true ? 1 : 0);
}

/* {{{ proto string dd_trace_compile_time_microseconds() */
PHP_FUNCTION(dd_trace_compile_time_microseconds) {
    UNUSED(execute_data);
    RETURN_LONG(ddtrace_compile_time_get());
}

PHP_FUNCTION(DDTrace_set_priority_sampling) {
    bool global = false;
    zend_long priority;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "l|b", &priority, &global) == FAILURE) {
        ddtrace_log_debug("Expected an integer and an optional boolean");
        RETURN_FALSE;
    }

    if (global || !DDTRACE_G(active_stack) || !DDTRACE_G(active_stack)->root_span) {
        DDTRACE_G(default_priority_sampling) = priority;
    } else {
        ddtrace_set_prioritySampling_on_root(priority);
    }
}

PHP_FUNCTION(DDTrace_get_priority_sampling) {
    zend_bool global = false;

    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "|b", &global) == FAILURE) {
        ddtrace_log_debug("Expected an optional boolean");
        RETURN_NULL();
    }

    if (global || !DDTRACE_G(active_stack) || !DDTRACE_G(active_stack)->root_span) {
        RETURN_LONG(DDTRACE_G(default_priority_sampling));
    }

    RETURN_LONG(ddtrace_fetch_prioritySampling_from_root());
}

PHP_FUNCTION(DDTrace_get_sanitized_exception_trace) {
    zend_object *ex;

    ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 1, 1)
        Z_PARAM_OBJ_OF_CLASS(ex, zend_ce_throwable)
    ZEND_PARSE_PARAMETERS_END_EX({
        ddtrace_log_debug("unexpected parameter for DDTrace\\get_sanitized_exception_trace. The first argument must be a Throwable.");
        RETURN_FALSE;
    });

    RETURN_STR(zai_get_trace_without_args_from_exception(ex));
}

PHP_FUNCTION(DDTrace_startup_logs) {
    UNUSED(execute_data);

    smart_str buf = {0};
    ddtrace_startup_logging_json(&buf, 0);
    ZVAL_NEW_STR(return_value, buf.s);
}

PHP_FUNCTION(DDTrace_find_active_exception) {
    UNUSED(execute_data);

    zend_object *ex = ddtrace_find_active_exception();
    if (ex) {
        RETURN_OBJ_COPY(ex);
    }
}

PHP_FUNCTION(DDTrace_extract_ip_from_headers) {
    zval *arr;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &arr) == FAILURE) {
        return;
    }

    zval meta;
    array_init(&meta);
    ddtrace_extract_ip_from_headers(arr, Z_ARR(meta));

    RETURN_ARR(Z_ARR(meta));
}

static const zend_module_dep ddtrace_module_deps[] = {ZEND_MOD_REQUIRED("json") ZEND_MOD_REQUIRED("standard")
                                                          ZEND_MOD_END};

zend_module_entry ddtrace_module_entry = {STANDARD_MODULE_HEADER_EX, NULL,
                                          ddtrace_module_deps,       PHP_DDTRACE_EXTNAME,
                                          ext_functions,             PHP_MINIT(ddtrace),
                                          PHP_MSHUTDOWN(ddtrace),    PHP_RINIT(ddtrace),
                                          PHP_RSHUTDOWN(ddtrace),    PHP_MINFO(ddtrace),
                                          PHP_DDTRACE_VERSION,       PHP_MODULE_GLOBALS(ddtrace),
                                          PHP_GINIT(ddtrace),        NULL,
                                          ddtrace_post_deactivate,   STANDARD_MODULE_PROPERTIES_EX};

// the following operations are performed in order to put the tracer in a state when a new trace can be started:
//   - set a new trace (group) id
void dd_prepare_for_new_trace(void) { DDTRACE_G(traces_group_id) = ddtrace_coms_next_group_id(); }

static ddtrace_trace_id dd_parse_b3_trace_id(char *trace_id, ssize_t trace_id_len) {
    return (ddtrace_trace_id){
        .high = trace_id_len > 16 ? ddtrace_parse_hex_span_id_str(trace_id, MIN(16, trace_id_len - 16)) : 0,
        .low = ddtrace_parse_hex_span_id_str(trace_id + MAX(0, trace_id_len - 16), MIN(16, trace_id_len)),
    };
}

void ddtrace_read_distributed_tracing_ids(bool (*read_header)(zai_string_view, const char *, zend_string **header_value, void *data), void *data) {
    zend_string *trace_id_str, *parent_id_str, *priority_str, *propagated_tags, *b3_header_str, *traceparent, *tracestate;

    DDTRACE_G(distributed_trace_id) = (ddtrace_trace_id){ 0 };
    DDTRACE_G(distributed_parent_trace_id) = 0;
    DDTRACE_G(dd_origin) = NULL;
    DDTRACE_G(tracestate) = NULL;

    zend_array *extract = zai_config_is_modified(DDTRACE_CONFIG_DD_TRACE_PROPAGATION_STYLE)
            && !zai_config_is_modified(DDTRACE_CONFIG_DD_TRACE_PROPAGATION_STYLE_EXTRACT)
            ? get_DD_TRACE_PROPAGATION_STYLE() : get_DD_TRACE_PROPAGATION_STYLE_EXTRACT();
    bool parse_datadog = zend_hash_str_exists(extract, ZEND_STRL("datadog"));
    bool parse_tracestate = zend_hash_str_exists(extract, ZEND_STRL("tracecontext"));
    bool parse_b3 = zend_hash_str_exists(extract, ZEND_STRL("b3")) || zend_hash_str_exists(extract, ZEND_STRL("b3multi"));
    bool parse_b3_single = zend_hash_str_exists(extract, ZEND_STRL("b3 single header"));

    int priority_sampling = DDTRACE_PRIORITY_SAMPLING_UNKNOWN;

    if (parse_b3_single && read_header(ZAI_STRL_VIEW("B3"), "b3", &b3_header_str, data)) {
        char *b3_ptr = ZSTR_VAL(b3_header_str), *b3_end = b3_ptr + ZSTR_LEN(b3_header_str);
        char *b3_traceid = b3_ptr;
        while (b3_ptr < b3_end && *b3_ptr != '-') {
            ++b3_ptr;
        }

        DDTRACE_G(distributed_trace_id) = dd_parse_b3_trace_id(b3_traceid, b3_ptr - b3_traceid);

        char *b3_spanid = ++b3_ptr;
        while (b3_ptr < b3_end && *b3_ptr != '-') {
            ++b3_ptr;
        }

        DDTRACE_G(distributed_parent_trace_id) = ddtrace_parse_hex_span_id_str(b3_spanid, b3_ptr - b3_spanid);

        char *b3_sampling = ++b3_ptr;
        while (b3_ptr < b3_end && *b3_ptr != '-') {
            ++b3_ptr;
        }

        if (b3_ptr - b3_sampling == 1) {
            if (*b3_sampling == '0') {
                priority_sampling = 0;
            } else if (*b3_sampling == '1') {
                priority_sampling = 1;
            } else if (*b3_sampling == 'd') {
                priority_sampling = PRIORITY_SAMPLING_USER_KEEP;
            }
        } else if (b3_ptr - b3_sampling == 4 && strncmp(b3_sampling, "true", 4) == 0) {
            priority_sampling = 1;
        } else if (b3_ptr - b3_sampling == 5 && strncmp(b3_sampling, "false", 5) == 0) {
            priority_sampling = 0;
        }
        zend_string_release(b3_header_str);
    }

    if (parse_datadog && read_header(ZAI_STRL_VIEW("X_DATADOG_TRACE_ID"), "x-datadog-trace-id", &trace_id_str, data)) {
        zval trace_zv;
        ZVAL_STR(&trace_zv, trace_id_str);
        DDTRACE_G(distributed_trace_id) = (ddtrace_trace_id){ .low = ddtrace_parse_userland_span_id(&trace_zv) };
        zend_string_release(trace_id_str);
    } else if (parse_b3 && read_header(ZAI_STRL_VIEW("X_B3_TRACEID"), "x-b3-traceid", &trace_id_str, data)) {
        DDTRACE_G(distributed_trace_id) = dd_parse_b3_trace_id(ZSTR_VAL(trace_id_str), ZSTR_LEN(trace_id_str));
        zend_string_release(trace_id_str);
    }

    if (DDTRACE_G(distributed_trace_id).low || DDTRACE_G(distributed_trace_id).high) {
        if (parse_datadog && read_header(ZAI_STRL_VIEW("X_DATADOG_PARENT_ID"), "x-datadog-parent-id", &parent_id_str, data)) {
            zval parent_zv;
            ZVAL_STR(&parent_zv, parent_id_str);
            DDTRACE_G(distributed_parent_trace_id) = ddtrace_parse_userland_span_id(&parent_zv);
            zend_string_release(parent_id_str);
        } else if (parse_b3 && read_header(ZAI_STRL_VIEW("X_B3_SPANID"), "x-b3-spanid", &parent_id_str, data)) {
            zval parent_zv;
            ZVAL_STR(&parent_zv, parent_id_str);
            DDTRACE_G(distributed_parent_trace_id) = ddtrace_parse_hex_span_id(&parent_zv);
            zend_string_release(parent_id_str);
        }
    }

    read_header(ZAI_STRL_VIEW("X_DATADOG_ORIGIN"), "x-datadog-origin", &DDTRACE_G(dd_origin), data);

    if (parse_datadog && read_header(ZAI_STRL_VIEW("X_DATADOG_SAMPLING_PRIORITY"), "x-datadog-sampling-priority", &priority_str, data)) {
        priority_sampling = strtol(ZSTR_VAL(priority_str), NULL, 10);
        zend_string_release(priority_str);
    } else if (parse_b3 && read_header(ZAI_STRL_VIEW("X_B3_SAMPLED"), "x-b3-sampled", &priority_str, data)) {
        if (ZSTR_LEN(priority_str) == 1) {
            if (ZSTR_VAL(priority_str)[0] == '0') {
                priority_sampling = 0;
            } else if (ZSTR_VAL(priority_str)[0] == '1') {
                priority_sampling = 1;
            }
        } else if (zend_string_equals_literal(priority_str, "true")) {
            priority_sampling = 1;
        } else if (zend_string_equals_literal(priority_str, "false")) {
            priority_sampling = 0;
        }
        zend_string_release(priority_str);
    } else if (parse_b3 && read_header(ZAI_STRL_VIEW("X_B3_FLAGS"), "x-b3-flags", &priority_str, data)) {
        if (ZSTR_LEN(priority_str) == 1 && ZSTR_VAL(priority_str)[1] == '1') {
            priority_sampling = PRIORITY_SAMPLING_USER_KEEP;
        }
        zend_string_release(priority_str);
    }

    if (read_header(ZAI_STRL_VIEW("X_DATADOG_TAGS"), "x-datadog-tags", &propagated_tags, data)) {
        ddtrace_add_tracer_tags_from_header(propagated_tags);
        zend_string_release(propagated_tags);
    }

    // "{version:2}-{trace-id:32}-{parent-id:16}-{trace-flags:2}"
    if (parse_tracestate && read_header(ZAI_STRL_VIEW("TRACEPARENT"), "traceparent", &traceparent, data)) {
        do {
            // version
            char *start = ZSTR_VAL(traceparent), *end = strchr(start, '-');
            while (*start == ' ' || *start == '\t') {
                ++start;
            }
            if (!end || end - start != 2) {
                break;
            }

            // trace-id
            start = end + 1;
            end = strchr(start, '-');
            if (!end || end - start != 32) {
                break;
            }
            DDTRACE_G(distributed_trace_id) = (ddtrace_trace_id) {
                .high = ddtrace_parse_hex_span_id_str(start, 16),
                .low = ddtrace_parse_hex_span_id_str(start + 16, 16)
            };

            // parent-id
            start = end + 1;
            end = strchr(start, '-');
            if (!end || end - start != 16) {
                break;
            }
            DDTRACE_G(distributed_parent_trace_id) = ddtrace_parse_hex_span_id_str(start, 16);

            if (!end[1] || !end[2]) {
                break;
            }
            priority_sampling = end[2] & 1;
        } while (0);
        zend_string_release(traceparent);

       // header format: "[*,]dd=s:1;o:rum;t.dm:-4;t.usr.id:12345[,*]"
        if (read_header(ZAI_STRL_VIEW("TRACESTATE"), "tracestate", &tracestate, data)) {
            bool last_comma = true;
            DDTRACE_G(tracestate) = zend_string_alloc(ZSTR_LEN(tracestate), 0);
            char *persist = ZSTR_VAL(DDTRACE_G(tracestate));
            zend_hash_clean(&DDTRACE_G(tracestate_unknown_dd_keys));
            int commas = 0;
            for (char *ptr = ZSTR_VAL(tracestate), *end = ptr + ZSTR_LEN(tracestate); ptr < end; ++ptr) {
                // dd member
                if (last_comma && ptr + 2 < end && ptr[0] == 'd' && ptr[1] == 'd' && (ptr[2] == '=' || ptr[2] == '\t' || ptr[2] == ' ')) {
                    ddtrace_clean_tracer_tags();

                    while (ptr < end && *ptr != '=') {
                        ++ptr;
                    }

                    do {
                        char *keystart = ++ptr;
                        while (ptr < end && *ptr != ';' && *ptr != ',' && *ptr != ':') {
                            ++ptr;
                        }
                        size_t keylen = ptr - keystart;
                        if (ptr >= end) {
                            break;
                        }
                        char *valuestart = ++ptr;
                        while (ptr < end && *ptr != ';' && *ptr != ',') {
                            ++ptr;
                        }
                        char *valueend = ptr;
                        while (*valueend == ' ' || *valueend == '\t') {
                            --valueend;
                        }
                        size_t valuelen = valueend - valuestart;

                        if (keylen == 1 && keystart[0] == 's') {
                            int extraced_priority = strtol(valuestart, NULL, 10);
                            if ((priority_sampling > 0) == (extraced_priority > 0)) {
                                priority_sampling = extraced_priority;
                            }
                        } else if (keylen == 1 && keystart[0] == 'o') {
                            if (DDTRACE_G(dd_origin)) {
                                zend_string_release(DDTRACE_G(dd_origin));
                            }
                            DDTRACE_G(dd_origin) = zend_string_init(valuestart, valuelen, 0);
                        } else if (keylen > 2 && keystart[0] == 't' && keystart[1] == '.') {
                            zend_string *tag_name = zend_strpprintf(0, "_dd.p.%.*s", (int) keylen - 2, keystart + 2);
                            zval zv;
                            ZVAL_STRINGL(&zv, valuestart, valuelen);
                            for (char *valptr = Z_STRVAL(zv), *valend = valptr + valuelen; valptr < valend; ++valptr) {
                                if (*valptr == '~') {
                                    *valptr = '=';
                                }
                            }
                            zend_hash_update(&DDTRACE_G(root_span_tags_preset), tag_name, &zv);
                            zend_hash_add_empty_element(&DDTRACE_G(propagated_root_span_tags), tag_name);
                            zend_string_release(tag_name);
                        } else {
                            zval zv;
                            ZVAL_STRINGL(&zv, valuestart, valuelen);
                            zend_hash_str_update(&DDTRACE_G(tracestate_unknown_dd_keys), keystart, keylen, &zv);
                        }
                    } while (*ptr == ';');

                    continue;
                }
                *(persist++) = *ptr;

                if (*ptr == ' ' || *ptr == '\t') {
                    continue;
                }

                last_comma = *ptr == ',';
                // preserve only up to 31 vendor specific values, excluding our own
                if (last_comma && ++commas == 30) {
                    --persist;
                    break;
                }
            }
            ZSTR_LEN(DDTRACE_G(tracestate)) = persist - ZSTR_VAL(DDTRACE_G(tracestate));
            zend_string_release(tracestate);
        }
    }

    zval *tidzv = zend_hash_str_find(&DDTRACE_G(root_span_tags_preset), ZEND_STRL("_dd.p.tid"));
    if (tidzv && DDTRACE_G(distributed_trace_id).low) {
        DDTRACE_G(distributed_trace_id).high = ddtrace_parse_hex_span_id(tidzv);
        zend_hash_str_del(&DDTRACE_G(root_span_tags_preset), ZEND_STRL("_dd.p.tid"));
    }

    if (priority_sampling != DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
        if (!DDTRACE_G(active_stack)->root_span) {
            DDTRACE_G(propagated_priority_sampling) = DDTRACE_G(default_priority_sampling) = priority_sampling;
        } else {
            ddtrace_set_prioritySampling_on_root(priority_sampling);
        }
    }
}

static bool dd_read_zai_header(zai_string_view zai_header, const char *lowercase_header, zend_string **header_value, void *data) {
    UNUSED(lowercase_header, data);
    if (zai_read_header(zai_header, header_value) != ZAI_HEADER_SUCCESS) {
        return false;
    }
    *header_value = zend_string_copy(*header_value);
    return true;
}

static void dd_read_distributed_tracing_ids(void) {
    ddtrace_read_distributed_tracing_ids(dd_read_zai_header, NULL);
}

