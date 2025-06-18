#ifndef DD_EXCEPTION_REPLAY_H
#define DD_EXCEPTION_REPLAY_H
#include "serializer.h"
#include <components-rs/ddtrace.h>

enum dd_exception {
    DD_EXCEPTION_THROWN,
    DD_EXCEPTION_CAUGHT,
    DD_EXCEPTION_UNCAUGHT,
};

void ddtrace_exception_to_meta(zend_object *exception, zend_string *service_name, uint64_t time, ddog_SpanBytes *context, enum dd_exception exception_state);
void ddtrace_create_capture_value(zval *zv, struct ddog_CaptureValue *value, const ddog_CaptureConfiguration *config, int remaining_nesting);

#endif // DD_EXCEPTION_REPLAY_H
