#ifndef DD_SERIALIZER_H
#define DD_SERIALIZER_H
#include "span.h"
#include "ddtrace_string.h"

int ddtrace_serialize_simple_array(zval *trace, zval *retval);
int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p);
size_t ddtrace_serialize_simple_array_into_mapped_menory(zval *trace, char *map, size_t size);

zval *ddtrace_serialize_span_to_array(ddtrace_span_data *span, zval *array);

void ddtrace_save_active_error_to_metadata(void);
void ddtrace_set_global_span_properties(ddtrace_span_data *span);
void ddtrace_set_root_span_properties(ddtrace_root_span_data *span);
void ddtrace_update_root_id_properties(ddtrace_root_span_data *span);
void ddtrace_inherit_span_properties(ddtrace_span_data *span, ddtrace_span_data *parent);
zend_string *ddtrace_default_service_name(void);
zend_string *ddtrace_active_service_name(void);

void ddtrace_initialize_span_sampling_limiter(void);
void ddtrace_shutdown_span_sampling_limiter(void);

void ddtrace_serializer_startup(void);

typedef zend_result (*add_tag_fn_t)(void *context, ddtrace_string key, ddtrace_string value);

#endif  // DD_SERIALIZER_H
