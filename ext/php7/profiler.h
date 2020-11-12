#ifndef DD_TRACE_PROFILER_H
#define DD_TRACE_PROFILER_H

typedef struct ddtrace_profiler ddtrace_profiler;

ddtrace_profiler *ddtrace_profiler_create(void);

void ddtrace_profiler_start(ddtrace_profiler *);
void ddtrace_profiler_stop(ddtrace_profiler *);
void ddtrace_profiler_join(ddtrace_profiler *);

void ddtrace_profiler_destroy(ddtrace_profiler *);

#endif  // DD_TRACE_PROFILER_H
