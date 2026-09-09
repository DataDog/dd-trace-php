#ifndef DATADOG_PROFILING_CPU_TIME_H
#define DATADOG_PROFILING_CPU_TIME_H

#include <stdbool.h>

typedef struct _zend_datadog_globals zend_datadog_globals;

bool datadog_profiling_cpu_time_minit(void);
void datadog_profiling_cpu_time_mshutdown(void);
bool datadog_profiling_cpu_time_rinit(bool enabled);
void datadog_profiling_cpu_time_rshutdown(void);
void datadog_profiling_cpu_time_gshutdown(zend_datadog_globals *globals);

#endif
