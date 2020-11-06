#ifndef DDTRACE_COMMS_PHP_H
#define DDTRACE_COMMS_PHP_H

#include <Zend/zend.h>
#include <curl/curl.h>
#include <stdbool.h>

#include "compatibility.h"

bool ddtrace_send_traces_via_thread(size_t num_traces, zval *curl_headers, char *payload, size_t payload_len TSRMLS_DC);

#endif  // DDTRACE_COMMS_PHP_H
