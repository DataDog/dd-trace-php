#pragma once

#include "ddappsec.h"
#include "dddefs.h"
#include "ddtrace.h"

extern ddtrace_user_req_listeners dd_user_req_listeners;

bool dd_req_is_user_req(void);
void dd_req_lifecycle_startup(void);
void dd_req_lifecycle_rinit(bool force);
void dd_req_lifecycle_rshutdown(bool ignore_verdict, bool force);
void dd_req_call_blocking_function(dd_result res);

zend_object *nullable dd_req_lifecycle_get_cur_span(void);
zend_string *nullable dd_req_lifecycle_get_client_ip(void);
