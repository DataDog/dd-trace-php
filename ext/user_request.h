#pragma once

#include "ddtrace.h"
#include "ddtrace_export.h"

typedef struct _ddtrace_user_req_listeners ddtrace_user_req_listeners;
struct _ddtrace_user_req_listeners {
    int priority;
    // entity is nullable
    zend_array *(*start_user_req)(ddtrace_user_req_listeners *self, zend_object *span, zend_array *variables, zval *entity);
    // headers is an array string => array(string). The header names are not normalized. entity is nullable.
    zend_array *(*response_committed)(ddtrace_user_req_listeners *self, zend_object *span, int status, zend_array *headers,
                                      zval *entity);
    void (*finish_user_req)(ddtrace_user_req_listeners *self, zend_object *span);
    void (*set_blocking_function)(ddtrace_user_req_listeners *self, zend_object *span, zval *blocking_function); // nullable
    void (*delete)(ddtrace_user_req_listeners *self); // nullable
};

// exported
DDTRACE_PUBLIC bool ddtrace_user_req_add_listeners(ddtrace_user_req_listeners *listeners);

void ddtrace_user_req_notify_finish(ddtrace_span_data *span);

void ddtrace_user_req_shutdown(void);
