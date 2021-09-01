#ifndef DDTRACE_COMMS_PHP_H
#define DDTRACE_COMMS_PHP_H

#include <Zend/zend.h>
#include <curl/curl.h>
#include <stdbool.h>

#include "compatibility.h"

static const size_t AGENT_REQUEST_BODY_LIMIT = 10485760;

bool ddtrace_send_traces_via_thread(size_t num_traces, char *payload, size_t payload_len);

#endif  // DDTRACE_COMMS_PHP_H
