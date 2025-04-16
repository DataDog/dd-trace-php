#include "handlers_internal.h"

#include "arrays.h"
#include "ddtrace.h"
#include "integrations/exec_integration.h"

void ddtrace_free_unregistered_class(zend_class_entry *ce) {
#if PHP_VERSION_ID >= 80100
    zend_property_info *prop_info;
    ZEND_HASH_FOREACH_PTR(&ce->properties_info, prop_info) {
        if (prop_info->ce == ce) {
            zend_string_release(prop_info->name);
            zend_type_release(prop_info->type, /* persistent */ 1);
            free(prop_info);
        }
    }
    ZEND_HASH_FOREACH_END();
#endif
    zend_hash_destroy(&ce->properties_info);
    if (ce->default_properties_table) {
        free(ce->default_properties_table);
    }
#if PHP_VERSION_ID >= 70400
    if (ce->properties_info_table) {
        free(ce->properties_info_table);
    }
#endif
}

void ddtrace_curl_handlers_startup(void);
void ddtrace_exception_handlers_startup(void);
void ddtrace_pcntl_handlers_startup(void);
void ddtrace_kafka_handlers_startup(void);
#ifndef _WIN32
void ddtrace_signal_block_handlers_startup(void);
#endif

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
#include <hook/hook.h>
#include <interceptor/php8/interceptor.h>

static HashTable dd_orig_internal_funcs;

static inline zend_ulong dd_identify_internal_func(zend_function *func) {
    return ((zend_ulong)(uintptr_t)func->common.scope) ^ zend_string_hash_val(func->common.function_name);
}

ZEND_NAMED_FUNCTION(dd_wrap_internal_func) {
    zif_handler handler;
    if ((handler = zend_hash_index_find_ptr(&dd_orig_internal_funcs, dd_identify_internal_func(EX(func))))) {
        zai_interceptor_execute_internal_with_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU, handler);
    }
}

static inline void dd_install_internal_func(zend_function *func) {
    zend_hash_index_add_ptr(&dd_orig_internal_funcs, dd_identify_internal_func(func), func->internal_function.handler);
    func->internal_function.handler = dd_wrap_internal_func;
}

static inline void dd_install_internal_func_name(HashTable *baseTable, const char *name) {
    zend_function *func;
    if ((func = zend_hash_str_find_ptr(baseTable, name, strlen(name)))) {
        dd_install_internal_func(func);
    }
}

static inline void dd_install_internal_function(const char *function) {
    dd_install_internal_func_name(CG(function_table), function);
}

static inline void dd_install_internal_method(const char *class, const char *method) {
    zend_class_entry *ce;
    if ((ce = zend_hash_str_find_ptr(CG(class_table), class, strlen(class)))) {
        dd_install_internal_func_name(&ce->function_table, method);
    }
}

static inline void dd_install_internal_class(const char *class) {
    zend_class_entry *ce;
    if ((ce = zend_hash_str_find_ptr(CG(class_table), class, strlen(class)))) {
        zend_function *func;
        ZEND_HASH_FOREACH_PTR(&ce->function_table, func) {
            dd_install_internal_func(func);
        } ZEND_HASH_FOREACH_END();
    }
}

static void dd_install_internal_handlers(void) {
    zend_hash_init(&dd_orig_internal_funcs, 32, NULL, NULL, true);
    dd_install_internal_class("memcached");
    dd_install_internal_class("redis");
    dd_install_internal_class("rediscluster");
    dd_install_internal_method("mysqli", "__construct");
    dd_install_internal_method("mysqli", "real_connect");
    dd_install_internal_method("mysqli", "query");
    dd_install_internal_method("mysqli", "prepare");
    dd_install_internal_method("mysqli", "commit");
    dd_install_internal_method("mysqli_stmt", "execute");
    dd_install_internal_method("mysqli_stmt", "get_result");
    dd_install_internal_method("PDO", "__construct");
    dd_install_internal_method("PDO", "exec");
    dd_install_internal_method("PDO", "query");
    dd_install_internal_method("PDO", "prepare");
    dd_install_internal_method("PDO", "commit");
    dd_install_internal_method("PDOStatement", "execute");
    dd_install_internal_function("mysqli_connect");
    dd_install_internal_function("mysqli_real_connect");
    dd_install_internal_function("mysqli_query");
    dd_install_internal_function("mysqli_prepare");
    dd_install_internal_function("mysqli_commit");
    dd_install_internal_function("mysqli_stmt_execute");
    dd_install_internal_function("mysqli_stmt_get_result");
    dd_install_internal_function("curl_exec");
    dd_install_internal_function("pcntl_fork");
    dd_install_internal_function("pcntl_rfork");
    dd_install_internal_function("DDTrace\\Integrations\\Exec\\register_stream");
    dd_install_internal_function("DDTrace\\Integrations\\Exec\\proc_assoc_span");
    dd_install_internal_function("DDTrace\\Integrations\\Exec\\proc_get_span");
    dd_install_internal_function("DDTrace\\Integrations\\Exec\\proc_get_pid");
    dd_install_internal_function("DDTrace\\Integrations\\Exec\\test_rshutdown");
}
#endif

#if PHP_VERSION_ID < 80000
void ddtrace_curl_handlers_shutdown(void);
#endif
void ddtrace_exception_handlers_shutdown(void);

void ddtrace_curl_handlers_rinit(void);
void ddtrace_exception_handlers_rinit(void);

void ddtrace_curl_handlers_rshutdown(void);

void ddtrace_internal_handlers_startup() {
    // On PHP 8.0 zend_execute_internal is not executed in JIT. Manually ensure internal hooks are executed.
#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
#if PHP_VERSION_ID >= 80100
    zend_long patch_version = Z_LVAL_P(zend_get_constant_str(ZEND_STRL("PHP_RELEASE_VERSION")));
    if (patch_version < 18)
#endif
    {
        dd_install_internal_handlers();
    }
#endif

    // curl is different; it has pieces that always run.
    ddtrace_curl_handlers_startup();
    // pcntl handlers have to run even if tracing of pcntl extension is not enabled.
    ddtrace_pcntl_handlers_startup();
    // exception handlers have to run otherwise wrapping will fail horribly
    ddtrace_exception_handlers_startup();

    ddtrace_exec_handlers_startup();
    ddtrace_kafka_handlers_startup();
#ifndef _WIN32
    // Block remote-config signals of some functions
    ddtrace_signal_block_handlers_startup();
#endif
}

void ddtrace_internal_handlers_shutdown(void) {
#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
    zend_hash_destroy(&dd_orig_internal_funcs);
#endif

    ddtrace_exception_handlers_shutdown();
#if PHP_VERSION_ID < 80000
    ddtrace_curl_handlers_shutdown();
#endif

    ddtrace_exec_handlers_shutdown();
}

void ddtrace_internal_handlers_rinit(void) {
    ddtrace_curl_handlers_rinit();
    ddtrace_exception_handlers_rinit();
    ddtrace_exec_handlers_rinit();
}

void ddtrace_internal_handlers_rshutdown(void) {
    ddtrace_curl_handlers_rshutdown();
    // called earlier in zm_deactivate_ddtrace
    // ddtrace_exec_handlers_rshutdown();
}
