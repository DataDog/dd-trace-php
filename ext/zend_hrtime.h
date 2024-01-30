#ifndef DD_HRTIME_H
#define DD_HRTIME_H

#include <main/php_version.h>
#include <Zend/zend_portability.h>
#include <Zend/zend_types.h>

#if PHP_VERSION_ID < 70300
#ifdef HAVE_UNISTD_H
# include <unistd.h>
#endif
#ifndef _WIN32
# include <time.h>
#endif

#define ZEND_HRTIME_PLATFORM_POSIX   0
#define ZEND_HRTIME_PLATFORM_WINDOWS 0
#define ZEND_HRTIME_PLATFORM_APPLE   0

#if defined(_POSIX_TIMERS) && ((_POSIX_TIMERS > 0) || defined(__OpenBSD__)) && defined(_POSIX_MONOTONIC_CLOCK) && defined(CLOCK_MONOTONIC)
# undef  ZEND_HRTIME_PLATFORM_POSIX
# define ZEND_HRTIME_PLATFORM_POSIX 1
#elif defined(_WIN32) || defined(_WIN64)
# undef  ZEND_HRTIME_PLATFORM_WINDOWS
# define ZEND_HRTIME_PLATFORM_WINDOWS 1
#elif defined(__APPLE__)
# undef  ZEND_HRTIME_PLATFORM_APPLE
# define ZEND_HRTIME_PLATFORM_APPLE 1
#else
# error "No hrtime available"
#endif

#if ZEND_HRTIME_PLATFORM_WINDOWS
ZEND_API extern double zend_hrtime_timer_scale;
#elif ZEND_HRTIME_PLATFORM_APPLE
# include <mach/mach_time.h>
# include <string.h>
extern mach_timebase_info_data_t zend_hrtime_timerlib_info;
#endif

#define ZEND_NANO_IN_SEC UINT64_C(1000000000)

typedef uint64_t zend_hrtime_t;

void ddtrace_startup_hrtime(void);

static zend_always_inline zend_hrtime_t zend_hrtime(void)
{
#if ZEND_HRTIME_PLATFORM_WINDOWS
    LARGE_INTEGER lt = {0};
	QueryPerformanceCounter(&lt);
	return (zend_hrtime_t)((zend_hrtime_t)lt.QuadPart * zend_hrtime_timer_scale);
#elif ZEND_HRTIME_PLATFORM_APPLE
    return (zend_hrtime_t)mach_absolute_time() * zend_hrtime_timerlib_info.numer / zend_hrtime_timerlib_info.denom;
#elif ZEND_HRTIME_PLATFORM_POSIX
    struct timespec ts = { .tv_sec = 0, .tv_nsec = 0 };
	if (EXPECTED(0 == clock_gettime(CLOCK_MONOTONIC, &ts))) {
		return ((zend_hrtime_t) ts.tv_sec * (zend_hrtime_t)ZEND_NANO_IN_SEC) + ts.tv_nsec;
	}
	return 0;
#endif
}
#elif PHP_VERSION_ID < 80300
#include <main/php.h>
#include <ext/standard/hrtime.h>
#define zend_hrtime_t php_hrtime_t
#define zend_hrtime php_hrtime_current
#define ZEND_NANO_IN_SEC UINT64_C(1000000000)
#else
#include <Zend/zend_hrtime.h>
#endif
#endif /* DD_HRTIME_H */