#include "arrays.h"

#include <php_version.h>

void ddtrace_array_walk(HashTable *input, ddtrace_walk_fn callback, void *context) {
    zval *item = NULL;
    size_t order = 0;
    ZEND_HASH_FOREACH_VAL(input, item) { callback(item, order++, context); }
    ZEND_HASH_FOREACH_END();
}

void *ddtrace_hash_find_ptr(const HashTable *ht, const char *str, size_t len) {
    void *result;
    result = zend_hash_str_find_ptr(ht, str, len);
    return result;
}

void *ddtrace_hash_find_ptr_lc(const HashTable *ht, const char *str, size_t len) {
    void *result;
    // Stack allocate small strings to improve performance
    ALLOCA_FLAG(use_heap)
    // zend_str_tolower_copy will add a null terminator; leave room for it
    char *lc_str = zend_str_tolower_copy(do_alloca(len + 1, use_heap), str, len);
    result = ddtrace_hash_find_ptr(ht, lc_str, len);
    free_alloca(lc_str, use_heap);
    return result;
}
