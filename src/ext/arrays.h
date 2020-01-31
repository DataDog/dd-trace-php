#ifndef DDTRACE_ARRAYS_H
#define DDTRACE_ARRAYS_H

#include <Zend/zend.h>

typedef void (*ddtrace_walk_fn)(zval *item, size_t visitation_order, void *context);
void ddtrace_array_walk(HashTable *input, ddtrace_walk_fn, void *context);

#endif  // DDTRACE_ARRAYS_H
