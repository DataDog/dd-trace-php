#include "configuration.h"

#include <stdlib.h>

#include "ddtrace_string.h"
#include "env_config.h"

extern inline ddtrace_string ddtrace_string_getenv(char* str, size_t len TSRMLS_DC);

struct ddtrace_memoized_configuration_t ddtrace_memoized_configuration = {
#define CHAR(...) NULL, FALSE,
#define BOOL(...) FALSE, FALSE,
#define INT(...) 0, FALSE,
#define DOUBLE(...) 0.0, FALSE,
    DD_CONFIGURATION
#undef CHAR
#undef BOOL
#undef INT
#undef DOUBLE
        PTHREAD_MUTEX_INITIALIZER};

void ddtrace_reload_config(TSRMLS_D) {
#define CHAR(getter_name, ...)                            \
    if (ddtrace_memoized_configuration.getter_name) {     \
        free(ddtrace_memoized_configuration.getter_name); \
    }                                                     \
    ddtrace_memoized_configuration.__is_set_##getter_name = FALSE;
#define BOOL(getter_name, ...) ddtrace_memoized_configuration.__is_set_##getter_name = FALSE;
#define INT(getter_name, ...) ddtrace_memoized_configuration.__is_set_##getter_name = FALSE;
#define DOUBLE(getter_name, ...) ddtrace_memoized_configuration.__is_set_##getter_name = FALSE;

    DD_CONFIGURATION

#undef CHAR
#undef BOOL
#undef INT
#undef DOUBLE
    // repopulate config
    ddtrace_initialize_config(TSRMLS_C);
}

void ddtrace_initialize_config(TSRMLS_D) {
    // read all values to memoize them

    // CHAR returns a copy of a value that we need to free
#define CHAR(getter_name, env_name, default, ...)                                  \
    do {                                                                           \
        pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);                 \
        ddtrace_memoized_configuration.getter_name =                               \
            ddtrace_get_c_string_config_with_default(env_name, default TSRMLS_CC); \
        ddtrace_memoized_configuration.__is_set_##getter_name = TRUE;              \
        pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);               \
    } while (0);
#define BOOL(getter_name, env_name, default, ...)                                                          \
    do {                                                                                                   \
        pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);                                         \
        ddtrace_memoized_configuration.getter_name = ddtrace_get_bool_config(env_name, default TSRMLS_CC); \
        ddtrace_memoized_configuration.__is_set_##getter_name = TRUE;                                      \
        pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);                                       \
    } while (0);
#define INT(getter_name, env_name, default, ...)                                                          \
    do {                                                                                                  \
        pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);                                        \
        ddtrace_memoized_configuration.getter_name = ddtrace_get_int_config(env_name, default TSRMLS_CC); \
        ddtrace_memoized_configuration.__is_set_##getter_name = TRUE;                                     \
        pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);                                      \
    } while (0);
#define DOUBLE(getter_name, env_name, default, ...)                                                          \
    do {                                                                                                     \
        pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);                                           \
        ddtrace_memoized_configuration.getter_name = ddtrace_get_double_config(env_name, default TSRMLS_CC); \
        ddtrace_memoized_configuration.__is_set_##getter_name = TRUE;                                        \
        pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);                                         \
    } while (0);

    DD_CONFIGURATION

#undef CHAR
#undef BOOL
#undef INT
#undef DOUBLE
}

void ddtrace_config_shutdown(void) {
    // read all values to memoize them

    // CHAR returns a copy of a value that we need to free
#define CHAR(getter_name, env_name, default, ...)                      \
    do {                                                               \
        pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);     \
        if (ddtrace_memoized_configuration.getter_name) {              \
            free(ddtrace_memoized_configuration.getter_name);          \
            ddtrace_memoized_configuration.getter_name = NULL;         \
        }                                                              \
        ddtrace_memoized_configuration.__is_set_##getter_name = FALSE; \
        pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);   \
    } while (0);
#define BOOL(getter_name, env_name, default, ...)                      \
    do {                                                               \
        pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);     \
        ddtrace_memoized_configuration.__is_set_##getter_name = FALSE; \
        pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);   \
    } while (0);
#define INT(getter_name, env_name, default, ...)                       \
    do {                                                               \
        pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);     \
        ddtrace_memoized_configuration.__is_set_##getter_name = FALSE; \
        pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);   \
    } while (0);
#define DOUBLE(getter_name, env_name, default, ...)                    \
    do {                                                               \
        pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);     \
        ddtrace_memoized_configuration.__is_set_##getter_name = FALSE; \
        pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);   \
    } while (0);

    DD_CONFIGURATION

#undef CHAR
#undef BOOL
#undef INT
#undef DOUBLE
}

// define configuration getters macros
#define CHAR(getter_name, ...) extern inline char* getter_name(void);
#define BOOL(getter_name, ...) extern inline bool getter_name(void);
#define INT(getter_name, ...) extern inline int64_t getter_name(void);
#define DOUBLE(getter_name, ...) extern inline double getter_name(void);

DD_CONFIGURATION

#undef CHAR
#undef BOOL
#undef INT
#undef DOUBLE

bool ddtrace_config_bool(ddtrace_string subject, bool default_value) {
    ddtrace_string str_1 = {
        .ptr = "1",
        .len = 1,
    };
    ddtrace_string str_true = {
        .ptr = "true",
        .len = sizeof("true") - 1,
    };
    if (ddtrace_string_equals(subject, str_1) || ddtrace_string_equals(subject, str_true)) {
        return true;
    }
    ddtrace_string str_0 = {
        .ptr = "0",
        .len = 1,
    };
    ddtrace_string str_false = {
        .ptr = "false",
        .len = sizeof("false") - 1,
    };
    if (ddtrace_string_equals(subject, str_0) || ddtrace_string_equals(subject, str_false)) {
        return false;
    }
    return default_value;
}

bool ddtrace_config_env_bool(ddtrace_string env_name, bool default_value TSRMLS_DC) {
    ddtrace_string env_val = ddtrace_string_getenv(env_name.ptr, env_name.len TSRMLS_CC);
    bool result = default_value;
    if (env_val.len) {
        /* We need to lowercase the str for ddtrace_config_bool.
         * It's been duplicated by ddtrace_getenv, so we can lower it in-place.
         */
        zend_str_tolower(env_val.ptr, env_val.len);
        result = ddtrace_config_bool(env_val, true);
    }
    if (env_val.ptr) {
        efree(env_val.ptr);
    }
    return result;
}

bool ddtrace_config_distributed_tracing_enabled(TSRMLS_D) {
    ddtrace_string env_name = DDTRACE_STRING_LITERAL("DD_DISTRIBUTED_TRACING");
    return ddtrace_config_env_bool(env_name, true TSRMLS_CC);
}

// note: only call this if ddtrace_config_trace_enabled() returns true
bool ddtrace_config_integration_enabled(ddtrace_string integration TSRMLS_DC) {
    ddtrace_string integrations_disabled = ddtrace_string_getenv(ZEND_STRL("DD_INTEGRATIONS_DISABLED") TSRMLS_CC);
    bool result = true;
    if (integrations_disabled.len && integration.len) {
        result = !ddtrace_string_contains_in_csv(integrations_disabled, integration);
    }
    if (integrations_disabled.ptr) {
        efree(integrations_disabled.ptr);
    }
    return result;
}

bool ddtrace_config_trace_enabled(TSRMLS_D) {
    ddtrace_string env_name = DDTRACE_STRING_LITERAL("DD_TRACE_ENABLED");
    return ddtrace_config_env_bool(env_name, true TSRMLS_CC);
}
