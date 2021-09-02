#include "../headers.h"

#include <php.h>
#include <zai_assert/zai_assert.h>

zai_header_result zai_read_header(zai_string_view uppercase_header_name, zai_string_view *header_value TSRMLS_DC) {
    if (!uppercase_header_name.ptr || !uppercase_header_name.len || !header_value) return ZAI_HEADER_ERROR;

    zai_assert_is_upper(uppercase_header_name.ptr, "Header names must be uppercase.");

    if (!PG(modules_activated) && !PG(during_request_startup)) return ZAI_HEADER_NOT_READY;

    if (PG(auto_globals_jit)) {
        // !!!
        // This has side effects: while it does not realistically affect anybodys code - it materializes the
        // $_SERVER array for users with auto_globals_jit On (which is observable from userland).
        // In reality, it does not affect us much, as we anyway have that sort of side effect as part of initializing
        // any span.
        // As long as this function is not called with tracing disabled, this should be fine.
        //
        // The alternative would be calling sapi_module_struct.register_server_variables manually, but this has an
        // unacceptable overhead as that always computes the *whole* _SERVER array, even if we just want to access
        // a single value.
        zend_is_auto_global(ZEND_STRL("_SERVER") TSRMLS_CC);
    }

    zval *server_var = PG(http_globals)[TRACK_VARS_SERVER];
    if (Z_TYPE_P(server_var) != IS_ARRAY) {
        return ZAI_HEADER_NOT_READY;  // should be impossible to reach
    }

    // note that ext/filter stores a raw (unfiltered, unmangled) version of the headers in IF_G(server_array)
    // but ext/filter is an optional module, so we cannot rely on this. Thus we directly access the _SERVER track vars
    // array, which may have been tampered with from user side, if called after RINIT, or also by ext/filter if there
    // is a default filter configured via ini. This should not impact us, but if it turns out to, we may have to
    // optionally access filter globals in a best-effort attempt at getting the original raw headers.

    // headers are present in HTTP_HEADERNAME from in the _SERVER array
    ALLOCA_FLAG(use_heap)
    size_t var_len = uppercase_header_name.len + sizeof("HTTP_");
    char *var_name = do_alloca(var_len, use_heap);
    memcpy(var_name, "HTTP_", 5);
    memcpy(var_name + 5, uppercase_header_name.ptr, uppercase_header_name.len + 1);  // incl trailing NULL

    zval **header_zv;
    zend_bool found_header = zend_hash_find(Z_ARRVAL_P(server_var), var_name, var_len, (void **)&header_zv) == SUCCESS;

    free_alloca(var_name, use_heap);

    if (!found_header || Z_TYPE_PP(header_zv) != IS_STRING) {
        return ZAI_HEADER_NOT_SET;
    }

    *header_value = (zai_string_view){
        .ptr = Z_STRVAL_PP(header_zv),
        .len = Z_STRLEN_PP(header_zv),
    };

    return ZAI_HEADER_SUCCESS;
}
