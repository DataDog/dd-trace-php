#ifndef DD_SERIALIZER_H
#define DD_SERIALIZER_H
#include "components-rs/common.h"
#include "span.h"

int ddtrace_serialize_simple_array(zval *trace, zval *retval);
int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p);

dd_span_sink ddtrace_serialize_span_to_rust_span(ddtrace_span_data *span, ddog_TraceBytes *trace, ddtrace_v1_ctx *v1);
zval dd_serialize_rust_traces_to_zval(ddog_TracesBytes *traces);
zval dd_serialize_rust_v1_to_zval(struct ddog_TracerPayloadV1Builder *builder);

// Span-meta sink ops with external linkage (routing shared with exception_serialize.c). Each writes
// to the sink's active backend: V0.4 SpanBytes meta, or a native V1 span attribute (with promoted /
// chunk-level key routing).
void dd_sink_meta_cs_cs(dd_span_sink *s, ddog_CharSlice key, ddog_CharSlice val);
void dd_sink_meta_str_cs(dd_span_sink *s, const char *key, ddog_CharSlice val);
void dd_sink_meta_str_str(dd_span_sink *s, const char *key, const char *val);
void dd_sink_meta_str_zstr(dd_span_sink *s, const char *key, zend_string *val);

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
