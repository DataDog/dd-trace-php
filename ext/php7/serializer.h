#ifndef DD_SERIALIZER_H
#define DD_SERIALIZER_H
#include "span.h"

int ddtrace_serialize_simple_array(zval *trace, zval *retval);
int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p);

void ddtrace_serialize_span_to_array(ddtrace_span_fci *span_fci, zval *array);

/* "int" return types are SUCCESS=0, anything else is a failure
 * Guarantees that add_tag will only be called once per tag, will stop trying to add tags if one fails.
 */
int ddtrace_exception_to_meta(zend_object *exception, void *context,
                              int (*add_tag)(void *context, ddtrace_string key, ddtrace_string value));
void ddtrace_save_active_error_to_metadata();

#endif  // DD_SERIALIZER_H
