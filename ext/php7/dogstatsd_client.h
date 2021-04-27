#ifndef DDTRACE_DOGSTATSD_CLIENT_H
#define DDTRACE_DOGSTATSD_CLIENT_H

#include "compatibility.h"

void ddtrace_dogstatsd_client_minit(TSRMLS_D);
void ddtrace_dogstatsd_client_rinit(TSRMLS_D);
void ddtrace_dogstatsd_client_rshutdown(TSRMLS_D);

#endif  // DDTRACE_DOGSTATSD_CLIENT_H
