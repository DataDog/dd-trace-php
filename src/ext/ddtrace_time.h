#ifndef DDTRACE_TIME_H
#define DDTRACE_TIME_H

#include <stdint.h>

uint64_t ddtrace_monotonic_now_nsec();
uint64_t ddtrace_monotonic_now_usec();

int64_t ddtrace_realtime_now_nsec();

#endif  // DDTRACE_TIME_H
