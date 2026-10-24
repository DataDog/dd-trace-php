// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.
#pragma once

// AppSec's settings, part of the table aggregated by ext/configuration.h.
// Settings shared with the tracer (DD_APPSEC_ENABLED, DD_SERVICE, ...) are
// defined there. Read them through configuration.h.

#include <config/config.h>
#include <stdbool.h>

bool dd_parse_log_level(zai_str value, zval *decoded_value, bool persistent);
bool dd_parse_user_collection_mode(
    zai_str value, zval *decoded_value, bool persistent);
bool dd_appsec_parse_uint32(
    zai_str value, zval *decoded_value, bool persistent);
bool dd_appsec_parse_uint64(
    zai_str value, zval *decoded_value, bool persistent);

// clang-format off
#define DEFAULT_OBFUSCATOR_KEY_REGEX                                           \
    "(?i)pass|pw(?:or)?d|secret|(?:api|private|public|access)[_-]?key|token|consumer[_-]?(?:id|key|secret)|sign(?:ed|ature)|bearer|authorization|jsessionid|phpsessid|asp\\.net[_-]sessionid|sid|jwt"

#define DEFAULT_OBFUSCATOR_VALUE_REGEX                                         \
    "(?i)(?:p(?:ass)?w(?:or)?d|pass(?:[_-]?phrase)?|secret(?:[_-]?key)?|(?:(?:api|private|public|access)[_-]?)key(?:[_-]?id)?|(?:(?:auth|access|id|refresh)[_-]?)?token|consumer[_-]?(?:id|key|secret)|sign(?:ed|ature)?|auth(?:entication|orization)?|jsessionid|phpsessid|asp\\.net(?:[_-]|-)sessionid|sid|jwt)(?:\\s*=([^;&]+)|\"\\s*:\\s*(\"[^\"]+\"|\\d+))|bearer\\s+([a-z0-9\\._\\-]+)|token\\s*:\\s*([a-z0-9]{13})|gh[opsu]_([0-9a-zA-Z]{36})|ey[I-L][\\w=-]+\\.(ey[I-L][\\w=-]+(?:\\.[\\w.+\\/=-]+)?)|[\\-]{5}BEGIN[a-z\\s]+PRIVATE\\sKEY[\\-]{5}([^\\-]+)[\\-]{5}END[a-z\\s]+PRIVATE\\sKEY|ssh-rsa\\s*([a-z0-9\\/\\.+]{100,})"

#define DDAPPSEC_CONFIGURATION \
    CONFIG(BOOL, DD_APPSEC_CLI_START_ON_RINIT, "false", .ini_change = zai_config_system_ini_change)                                    \
    CONFIG(STRING, DD_APPSEC_RULES, "", .ini_change = zai_config_system_ini_change)                                                   \
    CONFIG(CUSTOM(INT), DD_APPSEC_WAF_TIMEOUT, "10000", .ini_change = zai_config_system_ini_change,                                   \
           .parser = dd_appsec_parse_uint64)                                                                                          \
    CONFIG(CUSTOM(INT), DD_APPSEC_TRACE_RATE_LIMIT, "100", .ini_change = zai_config_system_ini_change,                                \
           .parser = dd_appsec_parse_uint32)                                                                                          \
    CONFIG(SET_LOWERCASE, DD_APPSEC_EXTRA_HEADERS, "", .ini_change = zai_config_system_ini_change)                                    \
    CONFIG(STRING, DD_APPSEC_OBFUSCATION_PARAMETER_KEY_REGEXP, DEFAULT_OBFUSCATOR_KEY_REGEX,                                          \
           .ini_change = zai_config_system_ini_change)                                                                                \
    CONFIG(STRING, DD_APPSEC_OBFUSCATION_PARAMETER_VALUE_REGEXP, DEFAULT_OBFUSCATOR_VALUE_REGEX,                                      \
           .ini_change = zai_config_system_ini_change)                                                                                \
    CONFIG(BOOL, DD_APPSEC_TESTING, "false", .ini_change = zai_config_system_ini_change)                                              \
    CONFIG(BOOL, DD_APPSEC_TESTING_ABORT_RINIT, "false", .ini_change = zai_config_system_ini_change)                                  \
    CONFIG(BOOL, DD_APPSEC_TESTING_RAW_BODY, "false", .ini_change = zai_config_system_ini_change)                                     \
    CONFIG(BOOL, DD_APPSEC_TESTING_HELPER_METRICS, "false", .ini_change = zai_config_system_ini_change)                               \
    CONFIG(BOOL, DD_APPSEC_TESTING_INVALID_COMMAND, "false", .ini_change = zai_config_system_ini_change)                              \
    CONFIG(CUSTOM(INT), DD_APPSEC_LOG_LEVEL, "warn", .parser = dd_parse_log_level)                                                    \
    CONFIG(STRING, DD_APPSEC_LOG_FILE, "php_error_reporting", .ini_change = zai_config_system_ini_change)                             \
    CONFIG(BOOL, DD_APPSEC_STACK_TRACE_ENABLED, "true", .ini_change = zai_config_system_ini_change)                                   \
    CONFIG(INT, DD_APPSEC_MAX_STACK_TRACE_DEPTH, "32", .ini_change = zai_config_system_ini_change)                                    \
    CONFIG(INT, DD_APPSEC_MAX_STACK_TRACES, "2", .ini_change = zai_config_system_ini_change)                                          \
    CONFIG(STRING, DD_APPSEC_HELPER_LOG_FILE, "<sidecar log>", .ini_change = zai_config_system_ini_change)                            \
    CONFIG(STRING, DD_APPSEC_HELPER_LOG_LEVEL, "info", .ini_change = zai_config_system_ini_change)                                    \
    CONFIG(INT, DD_APPSEC_MAX_BODY_BUFF_SIZE, "524288")                                                                               \
    CONFIG(BOOL, DD_APPSEC_RAW_RESPONSE_BODY_ENABLED, "false", .ini_change = zai_config_system_ini_change)                            \
    CALIAS(CUSTOM(STRING), DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE, "ident",                                                         \
           CALIASES("DD_APPSEC_AUTOMATED_USER_EVENTS_TRACKING"), .parser = dd_parse_user_collection_mode)                             \
    CONFIG(BOOL, DD_APPSEC_AUTOMATED_USER_EVENTS_TRACKING_ENABLED, "true")                                                            \
    CONFIG(STRING, DD_APPSEC_HTTP_BLOCKED_TEMPLATE_HTML, "")                                                                          \
    CONFIG(STRING, DD_APPSEC_HTTP_BLOCKED_TEMPLATE_JSON, "")                                                                          \
    CONFIG(BOOL, DD_API_SECURITY_ENABLED, "true", .ini_change = zai_config_system_ini_change)                                         \
    CONFIG(DOUBLE, DD_API_SECURITY_SAMPLE_DELAY, "30", .ini_change = zai_config_system_ini_change)
// clang-format on
