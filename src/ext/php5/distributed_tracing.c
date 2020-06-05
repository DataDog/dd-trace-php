#include "distributed_tracing.h"

#include <php.h>
#include <stdbool.h>

#include "ddtrace.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_distributed_tracing_rinit(TSRMLS_D) {
    DDTRACE_G(dt_http_headers) = NULL;
    DDTRACE_G(dt_http_saved_curl_headers) = NULL;
}

void ddtrace_distributed_tracing_rshutdown(TSRMLS_D) {
    if (DDTRACE_G(dt_http_headers)) {
        zend_hash_destroy(DDTRACE_G(dt_http_headers));
        FREE_HASHTABLE(DDTRACE_G(dt_http_headers));
        DDTRACE_G(dt_http_headers) = NULL;
    }
    if (DDTRACE_G(dt_http_saved_curl_headers)) {
        zend_hash_destroy(DDTRACE_G(dt_http_saved_curl_headers));
        FREE_HASHTABLE(DDTRACE_G(dt_http_saved_curl_headers));
        DDTRACE_G(dt_http_saved_curl_headers) = NULL;
    }
}

#define _DD_PARENT_ID_HEADER_LEN (sizeof(DDTRACE_HTTP_HEADER_PARENT_ID ":") - 1)

bool _dd_is_parent_id_header(zval **header) {
    size_t header_len = Z_STRLEN_PP(header);
    if (header_len >= _DD_PARENT_ID_HEADER_LEN &&
        strncmp(DDTRACE_HTTP_HEADER_PARENT_ID ":", Z_STRVAL_PP(header), _DD_PARENT_ID_HEADER_LEN) == 0) {
        return true;
    }
    return false;
}

void _dd_init_http_headers(TSRMLS_D) {
    if (!DDTRACE_G(dt_http_headers)) {
        ALLOC_HASHTABLE(DDTRACE_G(dt_http_headers));
        zend_hash_init(DDTRACE_G(dt_http_headers), 8, NULL, ZVAL_PTR_DTOR, 0);
    } else {
        zend_hash_clean(DDTRACE_G(dt_http_headers));
    }
}

int ddtrace_distributed_tracing_set_headers(zval *headers TSRMLS_DC) {
    zval **value;
    char *string_key;
    uint str_len;
    HashPosition iterator;
    zend_ulong num_key;
    int key_type;
    HashTable *ht = Z_ARRVAL_P(headers);

    _dd_init_http_headers(TSRMLS_C);

    zend_hash_internal_pointer_reset_ex(ht, &iterator);
    while (zend_hash_get_current_data_ex(ht, (void **)&value, &iterator) == SUCCESS) {
        key_type = zend_hash_get_current_key_ex(ht, &string_key, &str_len, &num_key, 0, &iterator);
        if (key_type != HASH_KEY_IS_LONG) {
            zend_hash_clean(DDTRACE_G(dt_http_headers));
            ddtrace_log_debug("Distributed tracing headers must be a numeric array");
            return 0;
        }
        if (Z_TYPE_PP(value) == IS_STRING) {
            // The parent ID must be the active span ID when the HTTP request happens, so ignore it for now
            if (!_dd_is_parent_id_header(value)) {
                zval_add_ref(value);
                zend_hash_next_index_insert(DDTRACE_G(dt_http_headers), value, sizeof(zval *), NULL);
            }
        } else {
            ddtrace_log_debugf("Distributed tracing headers must be strings. Ignoring header with index '%d'", num_key);
        }
        zend_hash_move_forward_ex(ht, &iterator);
    }
    return 1;
}
