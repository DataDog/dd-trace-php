#include "memory_limit.h"

#include <Zend/zend.h>
#include <php.h>

#include "configuration.h"
#include "ddtrace.h"
#include "serializer.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

int64_t ddtrace_get_memory_limit(void) {
    zend_string *raw_memory_limit = get_DD_TRACE_MEMORY_LIMIT();
    int64_t limit = -1;

    if (ZSTR_LEN(raw_memory_limit) == 0) {
        if (PG(memory_limit) > 0) {
            limit = PG(memory_limit) * ALLOWED_MAX_MEMORY_USE_IN_PERCENT_OF_MEMORY_LIMIT;
        } else {
            limit = -1;
        }
    } else {
        limit = zend_atol(ZSTR_VAL(raw_memory_limit), ZSTR_LEN(raw_memory_limit));
        if (ZSTR_VAL(raw_memory_limit)[ZSTR_LEN(raw_memory_limit) - 1] == '%') {
            if (PG(memory_limit) > 0) {
                limit = PG(memory_limit) * ((double)limit / 100.0);
            } else {
                limit = -1;
            }
        }
    }

    return limit;
}

bool ddtrace_check_memory_under_limit(void) {
    static int64_t limit = -1;
    static zend_bool fetched_limit = 0;
    if (!fetched_limit) {  // cache get_memory_limit() result to make this function blazing fast
        fetched_limit = 1;
        limit = ddtrace_get_memory_limit();
    }
    if (limit > 0) {
        return ((zend_ulong)limit > zend_memory_usage(0)) ? true : false;
    }
    return true;
}
