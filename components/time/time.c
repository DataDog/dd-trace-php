#include "time.h"

typedef datadog_php_system_time_result system_time_result_t;

#if DATADOG_HAVE_TIMESPEC_GET
/* timespec_get is from C11 and is supposed to be available on:
 * Linux on glibc 2.16+
 * Windows ... can't tell exactly, but at least VS 2019
 * Mac 10.15
 */
system_time_result_t datadog_php_system_time_now(struct timespec *now) {
    return timespec_get(now, TIME_UTC) == TIME_UTC ? DATADOG_PHP_SYSTEM_TIME_OK : DATADOG_PHP_SYSTEM_TIME_ERR;
}
#elif DATADOG_HAVE_CLOCK_GETTIME
/* clock_gettime is from POSIX:
 * Linux
 * Windows (unsupported at time of writing)
 * Mac 10.12+
 */
system_time_result_t datadog_php_system_time_now(struct timespec *now) {
    return clock_gettime(CLOCK_REALTIME, now) == 0 ? DATADOG_PHP_SYSTEM_TIME_OK : DATADOG_PHP_SYSTEM_TIME_ERR;
}
#else
#error Unhandled platform for system time
#endif

#if DATADOG_HAVE_PTHREAD_GETCPUCLOCKID

#include <errno.h>
#include <pthread.h>
#include <string.h>

// will probably syntax error if false
_Static_assert(DATADOG_HAVE_CLOCK_GETTIME, "HAVE_PTHREAD_GETCPUCLOCKID assumes DATADOG_HAVE_CLOCK_GETTIME");

datadog_php_cpu_time_result datadog_php_cpu_time_now(void) {
    struct timespec timespec;
    clockid_t clockid;  //  todo: cache this?

    if (pthread_getcpuclockid(pthread_self(), &clockid)) {
        return (datadog_php_cpu_time_result){
            .tag = DATADOG_PHP_CPU_TIME_ERR,
            .err = strerror(errno),
        };
    }

    if (clock_gettime(clockid, &timespec)) {
        return (datadog_php_cpu_time_result){
            .tag = DATADOG_PHP_CPU_TIME_ERR,
            .err = strerror(errno),
        };
    }

    return (datadog_php_cpu_time_result){
        .tag = DATADOG_PHP_CPU_TIME_OK,
        .ok = timespec,
    };
}

#elif DATADOG_HAVE_THREAD_INFO

#include <mach/mach_error.h>
#include <mach/mach_init.h>
#include <mach/thread_act.h>

datadog_php_cpu_time_result datadog_php_cpu_time_now(void) {
    mach_port_t thread = mach_thread_self();
    mach_msg_type_number_t count = THREAD_BASIC_INFO_COUNT;
    thread_basic_info_data_t info;
    kern_return_t kr = thread_info(thread, THREAD_BASIC_INFO, (thread_info_t)&info, &count);

    if (kr != KERN_SUCCESS) {
        return (datadog_php_cpu_time_result){
            .tag = DATADOG_PHP_CPU_TIME_ERR,
            .err = mach_error_string(kr),
        };
    }

    struct timespec timespec = {
        .tv_sec = info.user_time.seconds + info.system_time.seconds,
        .tv_nsec = (info.user_time.microseconds + info.system_time.microseconds) * 1000,
    };
    return (datadog_php_cpu_time_result){
        .tag = DATADOG_PHP_CPU_TIME_OK,
        .ok = timespec,
    };
}

#else
#error Unhandled platform for cpu time
#endif
