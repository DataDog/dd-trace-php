#ifndef DDTRACE_CLOCK_H
#define DDTRACE_CLOCK_H

#include <time.h>

#include "ddtrace_time.h"

typedef struct ddtrace_clock {
    ddtrace_nanotime (*now_nsec)(void);
    ddtrace_microtime (*now_usec)(void);
} ddtrace_clock;

inline int64_t ddtrace_now_nsec(clockid_t type) {
    struct timespec time;
    time.tv_sec = 0;
    time.tv_nsec = 0;
    clock_gettime(type, &time);
    int64_t seconds = time.tv_sec;
    int64_t nanoseconds = time.tv_nsec;
    return seconds * UINT64_C(1000000000) + nanoseconds;
}

inline int64_t ddtrace_now_usec(clockid_t type) {
    struct timespec time;
    time.tv_sec = 0;
    time.tv_nsec = 0;
    clock_gettime(type, &time);
    int64_t seconds = time.tv_sec;
    int64_t nanoseconds = time.tv_nsec;
    return seconds * UINT64_C(1000000) + nanoseconds / UINT64_C(1000);
}

inline ddtrace_nanotime ddtrace_monotonic_now_nsec(void) {
    return (ddtrace_nanotime){ddtrace_now_nsec(CLOCK_MONOTONIC)};
}
inline ddtrace_microtime ddtrace_monotonic_now_usec(void) {
    return (ddtrace_microtime){ddtrace_now_usec(CLOCK_MONOTONIC)};
}
inline ddtrace_nanotime ddtrace_realtime_now_nsec(void) { return (ddtrace_nanotime){ddtrace_now_nsec(CLOCK_REALTIME)}; }
inline ddtrace_microtime ddtrace_realtime_now_usec(void) {
    return (ddtrace_microtime){ddtrace_now_usec(CLOCK_REALTIME)};
}

// The steady clock is used like a stop-watch, for durations
static const ddtrace_clock ddtrace_steady_clock = {
    .now_nsec = ddtrace_monotonic_now_nsec,
    .now_usec = ddtrace_monotonic_now_usec,
};

// The system clock is used to relate to time in the real world
static const ddtrace_clock ddtrace_system_clock = {
    .now_nsec = ddtrace_realtime_now_nsec,
    .now_usec = ddtrace_realtime_now_usec,
};

#endif  // DDTRACE_CLOCK_H
