#include <curl/curl.h>
#include <inttypes.h>
#include <php.h>

#include "internal_functions.h"
#include "logging.h"
#include "random.h"
//#include "third-party/php/7.3/php_curl.h"

#if PHP_VERSION_ID < 70200
typedef void (*zif_handler)(INTERNAL_FUNCTION_PARAMETERS);
#endif

// BEGIN copy/pasted bits from ext/curl/php_curl.h (PHP 7)
//extern int le_curl;

typedef struct {
    zval func_name;
    zend_fcall_info_cache fci_cache;
    FILE *fp;
    smart_str buf;
    int method;
    zval stream;
} php_curl_write;

typedef struct {
    zval func_name;
    zend_fcall_info_cache fci_cache;
    FILE *fp;
    zend_resource *res;
    int method;
    zval stream;
} php_curl_read;

typedef struct {
    zval func_name;
    zend_fcall_info_cache fci_cache;
    int method;
} php_curl_progress, php_curl_fnmatch, php_curlm_server_push;

typedef struct {
    php_curl_write *write;
    php_curl_write *write_header;
    php_curl_read *read;
    zval std_err;
    php_curl_progress *progress;
#if LIBCURL_VERSION_NUM >= 0x071500 
    php_curl_fnmatch *fnmatch;
#endif
} php_curl_handlers;

struct _php_curl_error {
    char str[CURL_ERROR_SIZE + 1];
    int no;
};

struct _php_curl_send_headers {
    zend_string *str;
};

struct _php_curl_free {
    zend_llist str;
    zend_llist post;
    HashTable *slist;
};

typedef struct {
    CURL *cp;
    php_curl_handlers *handlers;
    zend_resource *res;
    struct _php_curl_free *to_free;
    struct _php_curl_send_headers header;
    struct _php_curl_error err;
    zend_bool in_callback;
    uint32_t *clone;
} php_curl;

// END copy/pasted bits

zif_handler orig_handler_curl_exec;
ZEND_NAMED_FUNCTION(ddtrace_hander_curl_exec) {
    zval *zid;
    php_curl *ch;
    struct curl_slist *orig_headers = NULL;
    struct curl_slist *last_orig_header = NULL;
    struct curl_slist *dd_headers = NULL;
    uint64_t root_span_id = ddtrace_root_span_id(TSRMLS_C);
    uint64_t active_span_id = ddtrace_peek_span_id(TSRMLS_C);

    // No trace ID to propagate
    if (!root_span_id) {
        orig_handler_curl_exec(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "r", &zid) == FAILURE) {
        return;
    }

    /*
    if ((ch = (php_curl *)zend_fetch_resource(Z_RES_P(zid), "cURL handle", le_curl)) == NULL) {
        return;
    }
    */
    ch = (php_curl *)Z_RES_P(zid)->ptr;  // FIX: This cannot be trusted without zend_fetch_resource()

    char header_trace_id[sizeof("x-datadog-trace-id: ") + DD_TRACE_MAX_ID_LEN + 1];
    char header_parent_id[sizeof("x-datadog-parent-id: ") + DD_TRACE_MAX_ID_LEN + 1];

    snprintf(header_trace_id, sizeof(header_trace_id), "x-datadog-trace-id: %" PRIu64, root_span_id);
    snprintf(header_parent_id, sizeof(header_parent_id), "x-datadog-parent-id: %" PRIu64, active_span_id);

    dd_headers = curl_slist_append(dd_headers, header_trace_id);
    dd_headers = curl_slist_append(dd_headers, header_parent_id);

    orig_headers = (struct curl_slist *)zend_hash_index_find_ptr(ch->to_free->slist, (zend_long)CURLOPT_HTTPHEADER);
    if (orig_headers) {
        last_orig_header = orig_headers;
        while (last_orig_header->next != NULL) {
            last_orig_header = last_orig_header->next;
        }
        last_orig_header->next = dd_headers;
        if (curl_easy_setopt(ch->cp, CURLOPT_HTTPHEADER, orig_headers) != CURLE_OK) {
            ddtrace_log_debug("Failed appending distributed trace headers");
        }
    } else {
        if (curl_easy_setopt(ch->cp, CURLOPT_HTTPHEADER, dd_headers) != CURLE_OK) {
            ddtrace_log_debug("Failed adding distributed trace headers");
        }
    }

    // Forward the original call
    orig_handler_curl_exec(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (last_orig_header) {
        last_orig_header->next = NULL;
    }
    curl_slist_free_all(dd_headers);
}

void ddtrace_hook_internal_functions() {
    zend_function *curl_exec;
    curl_exec = zend_hash_str_find_ptr(CG(function_table), "curl_exec", sizeof("curl_exec") - 1);
    if (curl_exec != NULL) {
        orig_handler_curl_exec = curl_exec->internal_function.handler;
        curl_exec->internal_function.handler = ddtrace_hander_curl_exec;
    }
}
