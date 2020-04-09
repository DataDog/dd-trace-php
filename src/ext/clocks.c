#include "clocks.h"

#include <php_config.h>   // for HAVE_CLOCK_GETTIME
#include <php_version.h>  // for PHP_VERSION_ID

// UNEXPECTED is in different places in PHP 5 and 7
#if PHP_VERSION_ID < 70000
#include <Zend/zend.h>
#else
#include <Zend/zend_portability.h>
#endif

#if HAVE_CLOCK_GETTIME

#include <time.h>  // for clock_gettime, CLOCK_MONOTONIC

ddtrace_monotonic_nsec_t ddtrace_monotonic_nsec(void) {
    struct timespec timespec;
    if (UNEXPECTED(clock_gettime(CLOCK_MONOTONIC, &timespec))) {
        return 0;
    }
    return ((uint64_t)timespec.tv_sec) * UINT64_C(1000000000) + ((uint64_t)timespec.tv_nsec);
}

ddtrace_monotonic_usec_t ddtrace_monotonic_usec(void) {
    struct timespec timespec;
    if (UNEXPECTED(clock_gettime(CLOCK_MONOTONIC, &timespec))) {
        return 0;
    }
    return ((uint64_t)timespec.tv_sec) * UINT64_C(1000000) + ((uint64_t)timespec.tv_nsec / UINT64_C(1000));
}

ddtrace_realtime_nsec_t ddtrace_realtime_nsec(void) {
    struct timespec timespec;
    if (UNEXPECTED(clock_gettime(CLOCK_REALTIME, &timespec))) {
        return 0;
    }
    return ((uint64_t)timespec.tv_sec) * UINT64_C(1000000000) + ((uint64_t)timespec.tv_nsec);
}
#else
ddtrace_monotonic_nsec_t ddtrace_monotonic_nsec(void) { return 0; }
ddtrace_monotonic_usec_t ddtrace_monotonic_usec(void) { return 0; }
ddtrace_realtime_nsec_t ddtrace_realtime_nsec(void) { return 0; }
#endif
