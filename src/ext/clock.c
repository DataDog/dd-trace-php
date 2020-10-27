#include "clock.h"

extern inline int64_t ddtrace_now_nsec(clockid_t type);
extern inline int64_t ddtrace_now_usec(clockid_t type);
extern inline ddtrace_nanotime ddtrace_monotonic_now_nsec(void);
extern inline ddtrace_microtime ddtrace_monotonic_now_usec(void);
extern inline ddtrace_nanotime ddtrace_realtime_now_nsec(void);
extern inline ddtrace_microtime ddtrace_realtime_now_usec(void);
