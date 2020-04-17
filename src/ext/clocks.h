#ifndef DDTRACE_CLOCKS_H
#define DDTRACE_CLOCKS_H

#include <stdint.h>

typedef uint64_t ddtrace_monotonic_nsec_t;
typedef uint64_t ddtrace_monotonic_usec_t;
typedef uint64_t ddtrace_realtime_nsec_t;

ddtrace_monotonic_nsec_t ddtrace_monotonic_nsec(void);
ddtrace_monotonic_usec_t ddtrace_monotonic_usec(void);
ddtrace_realtime_nsec_t ddtrace_realtime_nsec(void);

#endif  // DDTRACE_CLOCKS_H
