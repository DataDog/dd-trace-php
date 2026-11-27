#pragma once

#include "ddappsec.h"
#include "dddefs.h"
#include "request_abort.h"
#include "tracer_bridge.h"

extern ddtrace_user_req_listeners dd_user_req_listeners;

bool dd_req_is_user_req(void);
void dd_req_lifecycle_set_blocked(void);
bool dd_req_lifecycle_is_blocked(void);
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

bool dd_req_lifecycle_is_active(void);

// The validated reading of DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS
// (a negative value gives 1) and of
// DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE (outside [0, 1] gives
// 0.5); the only readers of the raw values.
uint64_t dd_downstream_limit(void);
double dd_downstream_rate(void);
// Picks the downstream requests whose bodies are sent to the WAF (RFC-1062):
// call once per downstream request, before sending any of its bodies.
bool dd_should_send_downstream_bodies(void);
// Gives back the slot a true dd_should_send_downstream_bodies() took, for a
// transfer that ended up sending no body.
void dd_downstream_release_slot(void);
// A transfer added its first body to a WAF call (call once per transfer): the
// request span gets the _dd.appsec.downstream_request metric, with the number
// of such transfers (G8.13); the slot counter of the limit is separate.
void dd_downstream_body_analysed(void);
zend_object *nullable dd_req_lifecycle_get_cur_span(void);
zend_string *nullable dd_req_lifecycle_get_client_ip(void);
