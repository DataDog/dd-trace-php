#ifndef DDTRACE_PRIORITY_SAMPLING_H
#define DDTRACE_PRIORITY_SAMPLING_H

#include "../ddtrace.h"

#define DDTRACE_UNKNOWN_PRIORITY_SAMPLING (1 << 30)

static const int PRIORITY_SAMPLING_USER_KEEP = 2;
static const int PRIORITY_SAMPLING_USER_REJECT = -1;

void ddtrace_set_prioritySampling_on_root(long priority TSRMLS_DC);
long ddtrace_fetch_prioritySampling_from_root(TSRMLS_D);

#endif // DDTRACE_PRIORITY_SAMPLING_H
