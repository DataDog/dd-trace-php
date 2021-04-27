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

void ddtrace_internal_handlers_install(ddtrace_string traced_internal_functions) {
    while (traced_internal_functions.len) {
        size_t delimiter = ddtrace_string_find_char(traced_internal_functions, ',');
        ddtrace_string segment = {
            .ptr = traced_internal_functions.ptr,
            .len = delimiter,
        };

        // let's look for a colon; signifies a method
        size_t colon = ddtrace_string_find_char(segment, ':');
        if (colon != delimiter) {
            // We need at least another colon and one char after the colon for this to be well-formed
            if (delimiter - colon >= 2 && segment.ptr[colon + 1] == ':') {
                ddtrace_string Class = {
                    .ptr = segment.ptr,
                    .len = colon,
                };
                ddtrace_string method = {
                    .ptr = segment.ptr + colon + 2,
                    .len = delimiter - colon - 2,
                };
                ddtrace_replace_internal_methods(Class, 1, &method);
            } else {
                // todo: should we warn?
            }
        } else {
            ddtrace_replace_internal_function(CG(function_table), segment);
        }

        char *ptr = traced_internal_functions.ptr;
        // delimiter will either be a position of comma or the end of string; skip the comma
        delimiter += ((delimiter == traced_internal_functions.len) ? 0 : 1);

        traced_internal_functions.ptr = ptr + delimiter;
        traced_internal_functions.len -= delimiter;
    }
}

void ddtrace_curl_handlers_startup(void);
void ddtrace_memcached_handlers_startup(void);
void ddtrace_mysqli_handlers_startup(void);
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

    // but the rest should be guarded
    if (ddtrace_resource == -1) {
        ddtrace_log_debug(
            "Unable to get a zend_get_resource_handle(); tracing of most internal functions is disabled.");
        return;
    }

    if (!get_dd_trace_sandbox_enabled()) {
        return;
    }

    ddtrace_memcached_handlers_startup();
    ddtrace_mysqli_handlers_startup();
    ddtrace_pdo_handlers_startup();
    ddtrace_phpredis_handlers_startup();

    // set up handlers for user-specified internal functions
    ddtrace_string traced_internal_functions = ddtrace_string_getenv(ZEND_STRL("DD_TRACE_TRACED_INTERNAL_FUNCTIONS"));
    if (traced_internal_functions.len) {
        zend_str_tolower(traced_internal_functions.ptr, traced_internal_functions.len);
        ddtrace_internal_handlers_install(traced_internal_functions);
    }
    if (traced_internal_functions.ptr) {
        efree(traced_internal_functions.ptr);
    }

    // These don't have a better place to go (yet, anyway)
    ddtrace_string handlers[] = {
        DDTRACE_STRING_LITERAL("header"),
        DDTRACE_STRING_LITERAL("http_response_code"),
    };
    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    ddtrace_replace_internal_functions(CG(function_table), handlers_len, handlers);
}

void ddtrace_internal_handlers_shutdown(void) {
    ddtrace_mysqli_handlers_shutdown();
    ddtrace_pdo_handlers_shutdown();
}

void ddtrace_internal_handlers_rinit(void) { ddtrace_curl_handlers_rinit(); }
void ddtrace_internal_handlers_rshutdown(void) { ddtrace_curl_handlers_rshutdown(); }
