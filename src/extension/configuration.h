// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#ifndef DD_CONFIGURATION_H
#define DD_CONFIGURATION_H

#include <stdbool.h>

#include <config/config.h>

bool dd_config_minit(int module_number);
void dd_config_first_rinit();

extern bool runtime_config_first_init;

#define DD_CFG_STR(str) #str
#define DD_CFG_EXPSTR(str) DD_CFG_STR(str)

// clang-format off
#define DEFAULT_OBFUSCATOR_KEY_REGEX                                           \
    "(?i)(?:p(?:ass)?w(?:or)?d|pass(?:_?phrase)?|secret|(?:api_?|private_?|public_?)key)|token|consumer_?(?:id|key|secret)|sign(?:ed|ature)|bearer|authorization"

#define DEFAULT_OBFUSCATOR_VALUE_REGEX                                         \
    "(?i)(?:p(?:ass)?w(?:or)?d|pass(?:_?phrase)?|secret|(?:api_?|private_?|public_?|access_?|secret_?)key(?:_?id)?|token|consumer_?(?:id|key|secret)|sign(?:ed|ature)?|auth(?:entication|orization)?)(?:\\s*=[^;]|\"\\s*:\\s*\"[^\"]+\")|bearer\\s+[a-z0-9\\._\\-]+|token:[a-z0-9]{13}|gh[opsu]_[0-9a-zA-Z]{36}|ey[I-L][\\w=-]+\\.ey[I-L][\\w=-]+(?:\\.[\\w.+\\/=-]+)?|[\\-]{5}BEGIN[a-z\\s]+PRIVATE\\sKEY[\\-]{5}[^\\-]+[\\-]{5}END[a-z\\s]+PRIVATE\\sKEY|ssh-rsa\\s*[a-z0-9\\/\\.+]{100,}"
// clang-format on

#define DD_BASE(path) "/opt/datadog-php/"

// clang-format off
#define DD_CONFIGURATION \
    SYSCFG(BOOL, DD_APPSEC_ENABLED, "false")                                                                    \
    SYSCFG(BOOL, DD_APPSEC_ENABLED_ON_CLI, "false")                                                             \
    SYSCFG(BOOL, DD_APPSEC_BLOCK, "false")                                                                      \
    SYSCFG(STRING, DD_APPSEC_RULES, "")                                                                         \
    SYSCFG(CUSTOM(uint64_t), DD_APPSEC_WAF_TIMEOUT, "10000", .parser = _parse_uint64)                           \
    SYSCFG(CUSTOM(uint32_t), DD_APPSEC_TRACE_RATE_LIMIT, "100", .parser = _parse_uint32)                        \
    SYSCFG(SET_LOWERCASE, DD_APPSEC_EXTRA_HEADERS, "")                                                          \
    SYSCFG(STRING, DD_APPSEC_OBFUSCATION_PARAMETER_KEY_REGEXP, DEFAULT_OBFUSCATOR_KEY_REGEX)                    \
    SYSCFG(STRING, DD_APPSEC_OBFUSCATION_PARAMETER_VALUE_REGEXP, DEFAULT_OBFUSCATOR_VALUE_REGEX)                \
    SYSCFG(BOOL, DD_APPSEC_TESTING, "false")                                                                    \
    SYSCFG(BOOL, DD_APPSEC_TESTING_ABORT_RINIT, "false")                                                        \
    SYSCFG(BOOL, DD_APPSEC_TESTING_RAW_BODY, "false")                                                           \
    CONFIG(CUSTOM(INT), DD_APPSEC_LOG_LEVEL, "warn", .parser = dd_parse_log_level)                              \
    SYSCFG(STRING, DD_APPSEC_LOG_FILE, "php_error_reporting")                                                   \
    SYSCFG(BOOL, DD_APPSEC_HELPER_LAUNCH, "true")                                                               \
    CONFIG(STRING, DD_APPSEC_HELPER_PATH, DD_BASE("bin/ddappsec-helper"))                                       \
    CONFIG(STRING, DD_APPSEC_HELPER_RUNTIME_PATH, "/tmp", .ini_change = dd_on_runtime_path_update)              \
    SYSCFG(STRING, DD_APPSEC_HELPER_LOG_FILE, "/dev/null")                                                      \
    CONFIG(STRING, DD_APPSEC_HELPER_EXTRA_ARGS, "")                                                             \
    CONFIG(STRING, DD_SERVICE, "", CALIASES("DD_SERVICE_NAME"))                                                 \
    CONFIG(STRING, DD_ENV, "")                                                                                  \
    CONFIG(CUSTOM(STRING), DD_TRACE_CLIENT_IP_HEADER, "", .parser = dd_parse_client_ip_header_config)           \
    CONFIG(BOOL, DD_REMOTE_CONFIG_ENABLED, "false")                                                             \
    CONFIG(CUSTOM(uint32_t), DD_REMOTE_CONFIG_POLL_INTERVAL, "1000", .parser = _parse_uint32)                   \
    CONFIG(CUSTOM(uint64_t), DD_REMOTE_CONFIG_MAX_PAYLOAD_SIZE, "4096", .parser = _parse_uint64)                \
    CONFIG(STRING, DD_AGENT_HOST, "")                                                                           \
    CONFIG(INT, DD_TRACE_AGENT_PORT, "8126")                                                                    \
// clang-format on

#define CALIAS CONFIG
#define SYSCFG CONFIG

#define CONFIG(type, name, ...) DDAPPSEC_CONFIG_##name,
typedef enum { DD_CONFIGURATION } dd_config_id;
#undef CONFIG
#undef SYSCFG

#define BOOL(name, value) \
    static inline bool name(void) { return IS_TRUE == Z_TYPE(value); }
#define INT(name, value) \
    static inline zend_long name(void) { return Z_LVAL(value); }
#define LVAL(name, value, type) \
    static inline type name(void) { return (type)Z_LVAL(value); }
#define uint32_t(name, value) LVAL(name, value, uint32_t)
#define uint64_t(name, value) LVAL(name, value, uint64_t)
#define DOUBLE(name, value) \
    static inline double name(void) { return Z_DVAL(value); }
#define STRING(name, value) \
    static inline zend_string *name(void) { return Z_STR(value); }
#define SET MAP
#define SET_LOWERCASE MAP
#define JSON MAP
#define MAP(name, value) \
    static inline zend_array *name(void) { return Z_ARR(value); }
#define CUSTOM(type) type

#define SYSCFG(type, name, ...) type(get_global_##name, zai_config_memoized_entries[DDAPPSEC_CONFIG_##name].decoded_value)
#define CONFIG(type, name, ...) type(get_##name, *zai_config_get_value(DDAPPSEC_CONFIG_##name)) SYSCFG(type, name)
DD_CONFIGURATION
#undef CONFIG
#undef SYSCFG

#undef STRING
#undef MAP
#undef SET
#undef SET_LOWERCASE
#undef JSON
#undef BOOL
#undef LVAL
#undef uint32_t
#undef uint64_t
#undef INT
#undef DOUBLE

#undef CUSTOM
#undef CALIAS

#endif  // DD_CONFIGURATION_H
