#ifndef DDTRACE_PRIORITY_SAMPLING_H
#define DDTRACE_PRIORITY_SAMPLING_H

#include "../ddtrace.h"
#include "../ddtrace_export.h"

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
    DD_MECHANISM_REMOTE_USER_RULE = 11,
    DD_MECHANISM_REMOTE_DYNAMIC_RULE = 12,
};

void ddtrace_set_priority_sampling_on_root(zend_long priority, enum dd_sampling_mechanism mechanism);
void ddtrace_set_priority_sampling_on_span(ddtrace_root_span_data *root_span, zend_long priority, enum dd_sampling_mechanism mechanism);
DDTRACE_PUBLIC void ddtrace_set_priority_sampling_on_span_zobj(zend_object *root_span, zend_long priority, enum dd_sampling_mechanism mechanism);
zend_long ddtrace_fetch_priority_sampling_from_span(ddtrace_root_span_data *root_span);
zend_long ddtrace_fetch_priority_sampling_from_root(void);
void ddtrace_decide_on_closed_span_sampling(ddtrace_span_data *span);

void ddtrace_try_read_agent_rate(void);

#endif  // DDTRACE_PRIORITY_SAMPLING_H
