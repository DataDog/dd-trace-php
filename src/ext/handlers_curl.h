#ifndef DDTRACE_HANDLERS_CURL_H
#define DDTRACE_HANDLERS_CURL_H

#include "compatibility.h"

void ddtrace_curl_handlers_startup(void);
void ddtrace_curl_handlers_rshutdown(void);

#endif  // DDTRACE_HANDLERS_CURL_H
