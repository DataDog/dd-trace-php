#ifndef DDTRACE_COMMS_PHP_H
#define DDTRACE_COMMS_PHP_H

#include <Zend/zend.h>
#include <curl/curl.h>
#include <stdbool.h>

#include "compatibility.h"

bool ddtrace_send_traces_via_thread(size_t num_traces, const char *payload, size_t payload_len);

#endif  // DDTRACE_COMMS_PHP_H
