#ifndef DD_SERIALIZER_H
#define DD_SERIALIZER_H
#include "components-rs/common.h"
#include "span.h"

int ddtrace_serialize_simple_array(zval *trace, zval *retval);
int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p);

// Returns false on allocation failure. A filtered span succeeds with a NULL result.
// serialized_span is optional; callers must stop serializing on failure.
bool ddtrace_serialize_span_to_rust_span(ddtrace_span_data *span, ddog_TraceBytes *trace, bool p0_trace, ddog_SpanBytes **serialized_span);
zval dd_serialize_rust_traces_to_zval(ddog_TracesBytes *traces);

void ddtrace_save_active_error_to_metadata(void);
void ddtrace_set_global_span_properties(ddtrace_span_data *span);
void ddtrace_set_root_span_properties(ddtrace_root_span_data *span);
void ddtrace_update_root_id_properties(ddtrace_root_span_data *span);
void ddtrace_inherit_span_properties(ddtrace_span_data *span, ddtrace_span_data *parent);
// Sets the request properties of a root span (URL with the query string
// obfuscated, method, resource, client IP, user agent, referrer, header tags)
// from a $_SERVER-shaped array instead of the current request's. A NULL method
// takes the current request's, or without one REQUEST_METHOD from the array.
void ddtrace_set_request_root_span_props(ddtrace_root_span_data *span, zend_array *server, const char *method);
// Sets http.status_code, and an HttpError error.type if the status is an error
// and the span has no error.type yet, so it must run after any error.type is set.
void ddtrace_set_root_span_http_status(ddtrace_root_span_data *span, int status);
zend_string *datadog_default_service_name(void);
zend_string *ddtrace_active_service_name(void);

void ddtrace_initialize_span_sampling_limiter(void);
void ddtrace_shutdown_span_sampling_limiter(void);

void ddtrace_serializer_startup(void);

#endif  // DD_SERIALIZER_H
