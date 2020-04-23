#include "arrays.h"

#include <php_version.h>

#if PHP_VERSION_ID < 70000
void ddtrace_array_walk(HashTable *input, ddtrace_walk_fn callback, void *context) {
    HashPosition pos;
    zval **operand;
    size_t order = 0;
    zend_hash_internal_pointer_reset_ex(input, &pos);
    while (zend_hash_get_current_data_ex(input, (void **)&operand, &pos) == SUCCESS) {
        callback(*operand, order++, context);
        zend_hash_move_forward_ex(input, &pos);
    }
}
#else
void ddtrace_array_walk(HashTable *input, ddtrace_walk_fn callback, void *context) {
    zval *item = NULL;
    size_t order = 0;
    ZEND_HASH_FOREACH_VAL(input, item) { callback(item, order++, context); }
    ZEND_HASH_FOREACH_END();
}
#endif

void *ddtrace_hash_find_ptr(HashTable *ht, const char *str, size_t len) {
    void *result;
#if PHP_VERSION_ID < 70000
    void **rv = NULL;
    result = zend_hash_find(ht, str, len, (void **)&rv) == SUCCESS ? *rv : NULL;
#else
    result = zend_hash_str_find_ptr(ht, str, len);
#endif
    return result;
}

void *ddtrace_hash_find_ptr_lc(HashTable *ht, const char *str, size_t len) {
    void *result;
    ALLOCA_FLAG(use_heap)
    // zend_str_tolower_copy will add a null terminator; leave room for it
    char *lc_str = zend_str_tolower_copy(do_alloca(len + 1, use_heap), str, len);
    result = ddtrace_hash_find_ptr(ht, lc_str, len);
    free_alloca(lc_str, use_heap);
    return result;
}
