#include "clocks.h"

#include <php_version.h>  // for PHP_VERSION_ID

#include "config.h"  // for DDTRACE_HAVE_CLOCK_GETTIME

// UNEXPECTED is in different places in PHP 5 and 7
#if PHP_VERSION_ID < 70000
#include <Zend/zend.h>
#else
#include <Zend/zend_portability.h>
#endif

#if !defined(DDTRACE_HAVE_CLOCK_GETTIME)
#error Unabled to find clock_gettime. Please open a bug report with the operating system information.
#endif

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
