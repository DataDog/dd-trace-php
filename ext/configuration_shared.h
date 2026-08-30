#ifndef DATADOG_CONFIGURATION_SHARED_H
#define DATADOG_CONFIGURATION_SHARED_H

#include <stdbool.h>
#include <main/php.h>
#include <config/config.h>

/*
 * Shared configuration declarations are named individually so the common
 * extension can preserve its historical ID ordering while standalone products
 * can compose just the shared subset in their own table.
 */
#if defined(PROFILING) && !defined(TRACER)
bool ddog_php_prof_config_parse_utf8_string(zai_str value, zval *decoded_value, bool persistent);

#define DD_COMMON_AGENT_HOST_CONFIGURATION                                                                    \
    CONFIG(STRING, DD_AGENT_HOST, "", .ini_change = zai_config_system_ini_change,                              \
           .parser = ddog_php_prof_config_parse_utf8_string)
#define DD_COMMON_ENV_CONFIGURATION                                                                            \
    CONFIG(STRING, DD_ENV, "", .parser = ddog_php_prof_config_parse_utf8_string)
#define DD_COMMON_SERVICE_CONFIGURATION                                                                        \
    CONFIG(STRING, DD_SERVICE, "", .parser = ddog_php_prof_config_parse_utf8_string)
#define DD_COMMON_TAGS_CONFIGURATION CONFIG(STRING, DD_TAGS, "")
#define DD_COMMON_TRACE_AGENT_PORT_CONFIGURATION                                                               \
    CONFIG(INT, DD_TRACE_AGENT_PORT, "0", .ini_change = zai_config_system_ini_change)
#define DD_COMMON_TRACE_AGENT_URL_CONFIGURATION                                                                \
    CONFIG(STRING, DD_TRACE_AGENT_URL, "", .ini_change = zai_config_system_ini_change,                         \
           .parser = ddog_php_prof_config_parse_utf8_string)
#define DD_COMMON_VERSION_CONFIGURATION                                                                        \
    CONFIG(STRING, DD_VERSION, "", .parser = ddog_php_prof_config_parse_utf8_string)
#define DD_COMMON_GIT_COMMIT_SHA_CONFIGURATION                                                                 \
    CONFIG(STRING, DD_GIT_COMMIT_SHA, "", .parser = ddog_php_prof_config_parse_utf8_string)
#define DD_COMMON_GIT_REPOSITORY_URL_CONFIGURATION                                                             \
    CONFIG(STRING, DD_GIT_REPOSITORY_URL, "", .parser = ddog_php_prof_config_parse_utf8_string)
#else
#define DD_COMMON_AGENT_HOST_CONFIGURATION                                                                     \
    CONFIG(STRING, DD_AGENT_HOST, "localhost", .ini_change = zai_config_system_ini_change)
#define DD_COMMON_ENV_CONFIGURATION                                                                            \
    CONFIG(STRING, DD_ENV, "", .ini_change = datadog_alter_dd_env,                                             \
           .env_config_fallback = ddtrace_conf_otel_resource_attributes_env)
#define DD_COMMON_SERVICE_CONFIGURATION                                                                        \
    CONFIG(STRING, DD_SERVICE, "", .ini_change = datadog_alter_dd_service,                                     \
           .env_config_fallback = ddtrace_conf_otel_service_name)
#define DD_COMMON_TAGS_CONFIGURATION                                                                           \
    CONFIG(CUSTOM(MAP), DD_TAGS, "",                                                                           \
           .env_config_fallback = ddtrace_conf_otel_resource_attributes_tags, .parser = dd_parse_tags)
#define DD_COMMON_TRACE_AGENT_PORT_CONFIGURATION                                                               \
    CONFIG(INT, DD_TRACE_AGENT_PORT, "8126", .ini_change = zai_config_system_ini_change)
#define DD_COMMON_TRACE_AGENT_URL_CONFIGURATION                                                                \
    CONFIG(STRING, DD_TRACE_AGENT_URL, "", .ini_change = zai_config_system_ini_change)
#define DD_COMMON_VERSION_CONFIGURATION                                                                        \
    CONFIG(STRING, DD_VERSION, "", .ini_change = datadog_alter_dd_version,                                     \
           .env_config_fallback = ddtrace_conf_otel_resource_attributes_version)
#define DD_COMMON_GIT_COMMIT_SHA_CONFIGURATION CONFIG(STRING, DD_GIT_COMMIT_SHA, "")
#define DD_COMMON_GIT_REPOSITORY_URL_CONFIGURATION CONFIG(STRING, DD_GIT_REPOSITORY_URL, "")
#endif

#define DATADOG_SHARED_CONFIGURATION        \
    DD_COMMON_AGENT_HOST_CONFIGURATION      \
    DD_COMMON_ENV_CONFIGURATION             \
    DD_COMMON_SERVICE_CONFIGURATION         \
    DD_COMMON_TAGS_CONFIGURATION            \
    DD_COMMON_TRACE_AGENT_PORT_CONFIGURATION \
    DD_COMMON_TRACE_AGENT_URL_CONFIGURATION \
    DD_COMMON_VERSION_CONFIGURATION         \
    DD_COMMON_GIT_COMMIT_SHA_CONFIGURATION  \
    DD_COMMON_GIT_REPOSITORY_URL_CONFIGURATION

#endif /* DATADOG_CONFIGURATION_SHARED_H */
