#include "arrays.h"

#include <php_version.h>

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

void *ddtrace_hash_find_ptr(const HashTable *ht, const char *str, size_t len) {
    void *result;
    void **rv = NULL;
    result = zend_hash_find(ht, str, len, (void **)&rv) == SUCCESS ? *rv : NULL;
    return result;
}

void *ddtrace_hash_find_ptr_lc(const HashTable *ht, const char *str, size_t len) {
    void *result;
    /* The code for the PHP 7 branch will also work on PHP 5, but if we do not
     * call emalloc and free (even if we don't end up using it), then there is
     * a memory leak reported by PHP 5.4 and 5.6:
     *   - 5.4: https://app.circleci.com/jobs/github/DataDog/dd-trace-php/142159
     *   - 5.6: https://app.circleci.com/jobs/github/DataDog/dd-trace-php/142298
     * So we always use an emalloc path until this is resolved.
     */
    char *lc_str = zend_str_tolower_dup(str, len - 1);
    result = ddtrace_hash_find_ptr(ht, lc_str, len);
    efree(lc_str);
    return result;
}
