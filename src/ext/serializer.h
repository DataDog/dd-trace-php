#ifndef DD_SERIALIZER_H
#define DD_SERIALIZER_H

// Plain-old PHP serialization
void ddtrace_serialize_span_stack_to_array(zval *retval TSRMLS_DC);
// MessagePack serialization
int ddtrace_serialize_simple_array(zval *trace, zval *retval TSRMLS_DC);
int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p TSRMLS_DC);

#endif  // DD_SERIALIZER_H
