#ifndef DDTRACE_DOGSTATSD_CLIENT_H
#define DDTRACE_DOGSTATSD_CLIENT_H

#include "compatibility.h"

void ddtrace_dogstatsd_client_minit(void);
void ddtrace_dogstatsd_client_rinit(void);
void ddtrace_dogstatsd_client_rshutdown(void);
char *ddtrace_dogstatsd_url(void);

#endif  // DDTRACE_DOGSTATSD_CLIENT_H
