#pragma once

#include "ddappsec.h"
#include "dddefs.h"
#include "ddtrace.h"
#include "request_abort.h"

extern ddtrace_user_req_listeners dd_user_req_listeners;

bool dd_req_is_user_req(void);
void dd_req_lifecycle_startup(void);
void dd_req_lifecycle_rinit(bool force);
void dd_req_lifecycle_rshutdown(bool ignore_verdict, bool force);

enum request_stage {
    REQUEST_STAGE_REQUEST_BEGIN,
    REQUEST_STAGE_MID_REQUEST,
    REQUEST_STAGE_REQUEST_END,
};
zend_array *nullable dd_req_lifecycle_abort(enum request_stage stage,
    dd_result result, struct block_params *nonnull block_params);

zend_object *nullable dd_req_lifecycle_get_cur_span(void);
zend_string *nullable dd_req_lifecycle_get_client_ip(void);
