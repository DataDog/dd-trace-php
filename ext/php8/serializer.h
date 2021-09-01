#ifndef DD_SERIALIZER_H
#define DD_SERIALIZER_H
#include "span.h"

int ddtrace_serialize_simple_array(zval *trace, zval *retval);
int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p);

void ddtrace_serialize_span_to_array(ddtrace_span_fci *span_fci, zval *array);

void ddtrace_save_active_error_to_metadata(void);

#endif  // DD_SERIALIZER_H
