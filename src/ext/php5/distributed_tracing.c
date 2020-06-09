#include "distributed_tracing.h"

#include <php.h>
#include <stdbool.h>

#include "ddtrace.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_distributed_tracing_rinit(TSRMLS_D) { DDTRACE_G(dt_http_saved_curl_headers) = NULL; }

void ddtrace_distributed_tracing_rshutdown(TSRMLS_D) {
    if (DDTRACE_G(dt_http_saved_curl_headers)) {
        zend_hash_destroy(DDTRACE_G(dt_http_saved_curl_headers));
        FREE_HASHTABLE(DDTRACE_G(dt_http_saved_curl_headers));
        DDTRACE_G(dt_http_saved_curl_headers) = NULL;
    }
}
