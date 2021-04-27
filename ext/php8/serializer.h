#ifndef DD_SERIALIZER_H
#define DD_SERIALIZER_H
#include "span.h"

int ddtrace_serialize_simple_array(zval *trace, zval *retval TSRMLS_DC);
int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p TSRMLS_DC);

void ddtrace_serialize_span_to_array(ddtrace_span_fci *span_fci, zval *array TSRMLS_DC);

/* "int" return types are SUCCESS=0, anything else is a failure
 * Guarantees that add_tag will only be called once per tag, will stop trying to add tags if one fails.
 */
int ddtrace_exception_to_meta(ddtrace_exception_t *exception, void *context,
                              int (*add_tag)(void *context, ddtrace_string key, ddtrace_string value));

#endif  // DD_SERIALIZER_H
