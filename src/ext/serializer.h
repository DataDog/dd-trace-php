#ifndef DD_SERIALIZER_H
#define DD_SERIALIZER_H

int ddtrace_serialize_simple_array(zval *trace, zval *retval TSRMLS_DC);
int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p TSRMLS_DC);

#endif  // DD_SERIALIZER_H
