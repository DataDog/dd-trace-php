#ifndef DDTRACE_PRIORITY_SAMPLING_H
#define DDTRACE_PRIORITY_SAMPLING_H

#include "../ddtrace.h"

#define DDTRACE_PRIORITY_SAMPLING_UNKNOWN (1 << 30)
#define DDTRACE_PRIORITY_SAMPLING_UNSET ((1 << 30) + 1)

static const int PRIORITY_SAMPLING_AUTO_KEEP = 1;
static const int PRIORITY_SAMPLING_AUTO_REJECT = 0;
static const int PRIORITY_SAMPLING_USER_KEEP = 2;
static const int PRIORITY_SAMPLING_USER_REJECT = -1;

enum dd_sampling_mechanism {
    DD_MECHANISM_DEFAULT = 0,
    DD_MECHANISM_AGENT_RATE = 1,
    DD_MECHANISM_REMOTE_RATE = 2,
    DD_MECHANISM_RULE = 3,
    DD_MECHANISM_MANUAL = 4,
};

void ddtrace_set_prioritySampling_on_root(zend_long priority, enum dd_sampling_mechanism mechanism);
zend_long ddtrace_fetch_prioritySampling_from_span(ddtrace_span_data *root_span);
zend_long ddtrace_fetch_prioritySampling_from_root(void);

#endif  // DDTRACE_PRIORITY_SAMPLING_H
