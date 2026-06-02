#ifndef COMPAT_STRING_H
#define COMPAT_STRING_H
#include <Zend/zend_types.h>

/**
 * dst will be IS_STRING after the call; caller must dtor.
 * Uses semantics similar to casting to string, except that:
 *   1. It will not emit warnings or throw exceptions
 *   2. Objects are in the form object(%s)#%d, where %s is the class name and %d is the object handle (like var_dump).
 *      The we avoid the __toString cast here to not execute user code at this place. Also avoids possible stack
 *      overflows due to recursion stemming from our own code as the root.
 **/
void datadog_convert_to_string(zval *dst, zval *src);
zend_string *datadog_convert_to_str(const zval *op);

#endif  // COMPAT_STRING_H
