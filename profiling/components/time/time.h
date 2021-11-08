#ifndef DATADOG_PHP_SYSTEM_TIME_H
#define DATADOG_PHP_SYSTEM_TIME_H

#include <stdint.h>
#include <time.h>

// This component exists to paper over differences in time across platforms.

typedef enum datadog_php_system_time_result {
  DATADOG_PHP_SYSTEM_TIME_OK = 0,
  DATADOG_PHP_SYSTEM_TIME_ERR,
} datadog_php_system_time_result;

datadog_php_system_time_result datadog_php_system_time_now(struct timespec *ts);

typedef enum datadog_php_cpu_time_result_tag {
  DATADOG_PHP_CPU_TIME_OK,
  DATADOG_PHP_CPU_TIME_ERR,
} datadog_php_cpu_time_result_tag;

typedef struct datadog_php_cpu_time_result_s {
  datadog_php_cpu_time_result_tag tag;
  union {
    struct timespec ok;
    const char *err; // c-string describing error; static lifetime
  };
} datadog_php_cpu_time_result;

datadog_php_cpu_time_result datadog_php_cpu_time_now(void);

#endif // DATADOG_PHP_SYSTEM_TIME_H
