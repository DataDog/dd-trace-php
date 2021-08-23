#include "memory_limit.h"

#include <Zend/zend.h>
#include <php.h>

#include "configuration.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

int64_t ddtrace_get_memory_limit(TSRMLS_D) {
    zai_string_view raw_memory_limit = get_DD_TRACE_MEMORY_LIMIT();
    int64_t limit = -1;

    if (raw_memory_limit.len == 0) {
        if (PG(memory_limit) > 0) {
            limit = PG(memory_limit) * ALLOWED_MAX_MEMORY_USE_IN_PERCENT_OF_MEMORY_LIMIT;
        } else {
            limit = -1;
        }
    } else {
        limit = zend_atol(raw_memory_limit.ptr, raw_memory_limit.len);
        if (raw_memory_limit.ptr[raw_memory_limit.len - 1] == '%') {
            if (PG(memory_limit) > 0) {
                limit = PG(memory_limit) * ((double)limit / 100.0);
            } else {
                limit = -1;
            }
        }
    }

    return limit;
}

bool ddtrace_check_memory_under_limit(TSRMLS_D) {
    static int64_t limit = -1;
    static zend_bool fetched_limit = 0;
    if (!fetched_limit) {  // cache get_memory_limit() result to make this function blazing fast
        fetched_limit = 1;
        limit = ddtrace_get_memory_limit(TSRMLS_C);
    }
    if (limit > 0) {
        return ((zend_ulong)limit > zend_memory_usage(0 TSRMLS_CC)) ? true : false;
    }
    return true;
}
