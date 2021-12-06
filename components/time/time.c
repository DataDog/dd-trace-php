#include "time.h"

#if DATADOG_HAVE_PTHREAD_GETCPUCLOCKID

#include <errno.h>
#include <pthread.h>
#include <string.h>

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
