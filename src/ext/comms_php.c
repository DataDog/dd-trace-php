#include "comms_php.h"

#include "arrays.h"
#include "compat_string.h"
#include "coms_curl.h"
#include "logging.h"

static void _comms_convert_append(zval *item, size_t offset, void *context) {
    struct curl_slist **list = context;
    zval converted;
    TSRMLS_FETCH();

    PHP5_UNUSED(offset);
    PHP7_UNUSED(offset);

    ddtrace_convert_to_string(&converted, item TSRMLS_CC);
    *list = curl_slist_append(*list, Z_STRVAL(converted));
    zval_dtor(&converted);
}

struct curl_slist *ddtrace_convert_hashtable_to_curl_slist(HashTable *input) {
    if (zend_hash_num_elements(input) > 0) {
        struct curl_slist *list = NULL;
        ddtrace_array_walk(input, _comms_convert_append, &list);
        return list;
    }
    return NULL;
}

bool ddtrace_memoize_http_headers(HashTable *input) {
    if (((struct curl_slist *)atomic_load(&memoized_agent_curl_headers)) == NULL && zend_hash_num_elements(input) > 0) {
        uintptr_t desired = (uintptr_t)ddtrace_convert_hashtable_to_curl_slist(input);
        uintptr_t expect = (uintptr_t)NULL;
        return atomic_compare_exchange_strong(&memoized_agent_curl_headers, &expect, desired);
    }
    return false;
}
