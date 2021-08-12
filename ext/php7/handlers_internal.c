#include "handlers_internal.h"

#include "arrays.h"
#include "configuration.h"
#include "ddtrace.h"
#include "engine_hooks.h"
#include "logging.h"

typedef void (*ddtrace_zif_handler)(INTERNAL_FUNCTION_PARAMETERS);

void ddtrace_replace_internal_function(const HashTable *ht, ddtrace_string fname) {
    zend_internal_function *function;
    function = zend_hash_str_find_ptr(ht, fname.ptr, fname.len);
    if (function && !function->reserved[ddtrace_resource]) {
        ddtrace_zif_handler old_handler = function->handler;
        function->handler = PHP_FN(ddtrace_internal_function_handler);
        function->reserved[ddtrace_resource] = old_handler;
    }
}

void ddtrace_replace_internal_functions(const HashTable *ht, size_t functions_len, ddtrace_string functions[]) {
    for (size_t i = 0; i < functions_len; ++i) {
        ddtrace_string *fname = functions + i;
        ddtrace_replace_internal_function(ht, *fname);
    }
}

void ddtrace_replace_internal_methods(ddtrace_string Class, size_t methods_len, ddtrace_string methods[]) {
    zval *zv = zend_hash_str_find(CG(class_table), Class.ptr, Class.len);
    if (!zv) {
        return;
    }

    zend_class_entry *ce = Z_PTR_P(zv);
    if (!ce) {
        return;
    }

    HashTable *function_table = &ce->function_table;
    if (!function_table) {
        return;
    }

    ddtrace_replace_internal_functions(function_table, methods_len, methods);
}

void ddtrace_internal_handlers_install(zend_array *traced_internal_functions) {
    zend_string *function;
    ZEND_HASH_FOREACH_STR_KEY(traced_internal_functions, function) {
        function = zend_string_tolower(zend_string_dup(function, 1));
        // let's look for a colon; signifies a method
        char *colon = strstr(ZSTR_VAL(function), "::");
        if (colon) {
            ddtrace_string Class = {
                .ptr = ZSTR_VAL(function),
                .len = colon - ZSTR_VAL(function),
            };
            ddtrace_string method = {
                .ptr = colon + 2,
                .len = ZSTR_LEN(function) - (colon - ZSTR_VAL(function) + 2),
            };
            ddtrace_replace_internal_methods(Class, 1, &method);
        } else {
            ddtrace_replace_internal_function(CG(function_table), (ddtrace_string){
                                                                      .ptr = ZSTR_VAL(function),
                                                                      .len = ZSTR_LEN(function),
                                                                  });
        }
        zend_string_release(function);
    }
    ZEND_HASH_FOREACH_END();
}

void ddtrace_curl_handlers_startup(void);
void ddtrace_memcached_handlers_startup(void);
void ddtrace_mysqli_handlers_startup(void);
void ddtrace_pcntl_handlers_startup(void);
void ddtrace_pdo_handlers_startup(void);
void ddtrace_phpredis_handlers_startup(void);

void ddtrace_mysqli_handlers_shutdown(void);
void ddtrace_pdo_handlers_shutdown(void);

void ddtrace_curl_handlers_rinit(void);
void ddtrace_curl_handlers_rshutdown(void);

// Internal handlers use ddtrace_resource and only implement the sandbox API.
void ddtrace_internal_handlers_startup(void) {
    // curl is different; it has pieces that always run.
    ddtrace_curl_handlers_startup();
    // pcntl handlers have to run even if tracing of pcntl extension is not enabled.
    ddtrace_pcntl_handlers_startup();

    // but the rest should be guarded
    if (ddtrace_resource == -1) {
        ddtrace_log_debug(
            "Unable to get a zend_get_resource_handle(); tracing of most internal functions is disabled.");
        return;
    }

    ddtrace_memcached_handlers_startup();
    ddtrace_mysqli_handlers_startup();
    ddtrace_pdo_handlers_startup();
    ddtrace_phpredis_handlers_startup();

    // set up handlers for user-specified internal functions
    // we directly access the backing storage instead of get_DD_INTEGRATIONS_DISABLED, because the latter is not
    // available during MINIT
    ddtrace_internal_handlers_install(get_global_DD_TRACE_TRACED_INTERNAL_FUNCTIONS());

    // These don't have a better place to go (yet, anyway)
}

void ddtrace_internal_handlers_shutdown(void) {
    ddtrace_mysqli_handlers_shutdown();
    ddtrace_pdo_handlers_shutdown();
}

void ddtrace_internal_handlers_rinit(void) { ddtrace_curl_handlers_rinit(); }
void ddtrace_internal_handlers_rshutdown(void) { ddtrace_curl_handlers_rshutdown(); }
