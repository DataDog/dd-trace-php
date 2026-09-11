#ifndef DDTRACE_PROFILING_CONFIGURATION_H
#define DDTRACE_PROFILING_CONFIGURATION_H

#include <stdbool.h>
#include <main/php.h>
#include <config/config.h>
#include <ext/configuration_shared.h>

bool ddog_php_prof_config_parse_sampling_distance(zai_str value, zval *decoded_value, bool persistent);
bool ddog_php_prof_config_parse_log_level(zai_str value, zval *decoded_value, bool persistent);
bool ddog_php_prof_config_parse_enabled(zai_str value, zval *decoded_value, bool persistent);
void ddog_php_prof_config_display_enabled(zend_ini_entry *ini_entry, int type);
bool ddog_php_prof_config_parse_utf8_string(zai_str value, zval *decoded_value, bool persistent);

#ifdef TRACER
#define DDTRACE_PROFILING_ENABLED_DEFAULT "0"
#else
#define DDTRACE_PROFILING_ENABLED_DEFAULT "1"
#endif

#define DDTRACE_PROFILING_CONFIGURATION                                                                                         \
    CONFIG(CUSTOM(BOOL), DD_PROFILING_ENABLED, DDTRACE_PROFILING_ENABLED_DEFAULT,                                               \
           .ini_change = zai_config_system_ini_change,                                                                          \
           .parser = ddog_php_prof_config_parse_enabled, .displayer = ddog_php_prof_config_display_enabled)          \
    CONFIG(BOOL, DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED, "0", .ini_change = zai_config_system_ini_change)       \
    CONFIG(BOOL, DD_PROFILING_ENDPOINT_COLLECTION_ENABLED, "1", .ini_change = zai_config_system_ini_change)        \
    CALIAS(BOOL, DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED, "1",                                                   \
           CALIASES("DD_PROFILING_EXPERIMENTAL_CPU_ENABLED"), .ini_change = zai_config_system_ini_change)           \
    CALIAS(BOOL, DD_PROFILING_ALLOCATION_ENABLED, "1", CALIASES("DD_PROFILING_EXPERIMENTAL_ALLOCATION_ENABLED"),  \
           .ini_change = zai_config_system_ini_change)                                                               \
    CONFIG(CUSTOM(INT), DD_PROFILING_ALLOCATION_SAMPLING_DISTANCE, "4194304",                                      \
           .ini_change = zai_config_system_ini_change, .parser = ddog_php_prof_config_parse_sampling_distance)       \
    CONFIG(BOOL, DD_PROFILING_EXPERIMENTAL_HEAP_LIVE_ENABLED, "0", .ini_change = zai_config_system_ini_change)     \
    CALIAS(BOOL, DD_PROFILING_TIMELINE_ENABLED, "1", CALIASES("DD_PROFILING_EXPERIMENTAL_TIMELINE_ENABLED"),      \
           .ini_change = zai_config_system_ini_change)                                                               \
    CALIAS(BOOL, DD_PROFILING_EXCEPTION_ENABLED, "1", CALIASES("DD_PROFILING_EXPERIMENTAL_EXCEPTION_ENABLED"),    \
           .ini_change = zai_config_system_ini_change)                                                               \
    CONFIG(BOOL, DD_PROFILING_EXCEPTION_MESSAGE_ENABLED, "0", .ini_change = zai_config_system_ini_change)          \
    CALIAS(CUSTOM(INT), DD_PROFILING_EXCEPTION_SAMPLING_DISTANCE, "100",                                           \
           CALIASES("DD_PROFILING_EXPERIMENTAL_EXCEPTION_SAMPLING_DISTANCE"),                                      \
           .ini_change = zai_config_system_ini_change, .parser = ddog_php_prof_config_parse_sampling_distance)       \
    CONFIG(BOOL, DD_PROFILING_EXPERIMENTAL_IO_ENABLED, "0", .ini_change = zai_config_system_ini_change)            \
    CONFIG(CUSTOM(INT), DD_PROFILING_LOG_LEVEL, "off", .ini_change = zai_config_system_ini_change,                 \
           .parser = ddog_php_prof_config_parse_log_level)                                                           \
    CONFIG(STRING, DD_PROFILING_OUTPUT_PPROF, "", .ini_change = zai_config_system_ini_change,                      \
           .parser = ddog_php_prof_config_parse_utf8_string)                                                         \
    CONFIG(BOOL, DD_PROFILING_WALLTIME_ENABLED, "1", .ini_change = zai_config_system_ini_change)

#define DDTRACE_PROFILING_ALL_CONFIGURATIONS \
    DDTRACE_PROFILING_CONFIGURATION          \
    DATADOG_SHARED_CONFIGURATION

#endif /* DDTRACE_PROFILING_CONFIGURATION_H */
