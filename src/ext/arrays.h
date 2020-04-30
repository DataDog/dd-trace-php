#ifndef DDTRACE_ARRAYS_H
#define DDTRACE_ARRAYS_H

#include <Zend/zend.h>

typedef void (*ddtrace_walk_fn)(zval *item, size_t visitation_order, void *context);
void ddtrace_array_walk(HashTable *input, ddtrace_walk_fn, void *context);

/**
 * Use ddtrace_hash_find_ptr_lc if you do not already have a lowercased string
 * and do not need one for any reason other than to look up the string in the
 * ht.
 * If you already have a lowered string, use ddtrace_hash_find_ptr.
 */
void *ddtrace_hash_find_ptr_lc(HashTable *ht, const char *str, size_t len);
void *ddtrace_hash_find_ptr(HashTable *ht, const char *str, size_t len);

#endif  // DDTRACE_ARRAYS_H
