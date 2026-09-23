#ifndef DD_SERIALIZER_H
#define DD_SERIALIZER_H
#include "components-rs/common.h"
#include "span.h"

int ddtrace_serialize_simple_array(zval *trace, zval *retval);
int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p);

// Returns the span's V1 builder node, or NULL when the span was dropped.
ddog_SpanNode *ddtrace_serialize_span_to_rust_span(ddtrace_span_data *span, ddtrace_serialize_ctx *ctx);
zval dd_serialize_rust_to_zval(struct ddog_TracerPayloadV1Builder *builder);

// String span attribute setters shared with exception_serialize.c.
void dd_span_attr_str(ddog_SpanNode *span, const char *key, const char *val);
void dd_span_attr_zstr(ddog_SpanNode *span, const char *key, zend_string *val);

void ddtrace_save_active_error_to_metadata(void);
void ddtrace_set_global_span_properties(ddtrace_span_data *span);
void ddtrace_set_root_span_properties(ddtrace_root_span_data *span);
void ddtrace_update_root_id_properties(ddtrace_root_span_data *span);
void ddtrace_inherit_span_properties(ddtrace_span_data *span, ddtrace_span_data *parent);
zend_string *datadog_default_service_name(void);
zend_string *ddtrace_active_service_name(void);

void ddtrace_initialize_span_sampling_limiter(void);
void ddtrace_shutdown_span_sampling_limiter(void);

void ddtrace_serializer_startup(void);

#endif  // DD_SERIALIZER_H
