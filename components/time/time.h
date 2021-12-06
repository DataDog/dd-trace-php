#ifndef DATADOG_PHP_SYSTEM_TIME_H
#define DATADOG_PHP_SYSTEM_TIME_H

#include <stdint.h>
#include <time.h>

/* This component exists to paper over differences in time across platforms.
 * In the past, this included monotonic, system, and cpu time. It seems feasible
 * that we would re-introduce those other types, so I left the name as a
 * generic "time" component instead of renaming to cpu-time.
 */

typedef enum datadog_php_cpu_time_result_tag {
    DATADOG_PHP_CPU_TIME_OK,
    DATADOG_PHP_CPU_TIME_ERR,
} datadog_php_cpu_time_result_tag;

typedef struct datadog_php_cpu_time_result_s {
    datadog_php_cpu_time_result_tag tag;
    union {
        struct timespec ok;
        const char *err;  // c-string describing error; static lifetime
    };
} datadog_php_cpu_time_result;

datadog_php_cpu_time_result datadog_php_cpu_time_now(void);

#endif  // DATADOG_PHP_SYSTEM_TIME_H
