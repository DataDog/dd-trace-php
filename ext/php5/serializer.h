#ifndef DD_SERIALIZER_H
#define DD_SERIALIZER_H
#include "span.h"

int ddtrace_serialize_simple_array(zval *trace, zval *retval TSRMLS_DC);
int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p TSRMLS_DC);

void ddtrace_serialize_span_to_array(ddtrace_span_fci *span_fci, zval *array TSRMLS_DC);

void ddtrace_save_active_error_to_metadata(TSRMLS_D);
void ddtrace_set_global_span_properties(ddtrace_span_t *span TSRMLS_DC);
void ddtrace_set_root_span_properties(ddtrace_span_t *span TSRMLS_DC);

#endif  // DD_SERIALIZER_H
