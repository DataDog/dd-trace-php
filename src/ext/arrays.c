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
