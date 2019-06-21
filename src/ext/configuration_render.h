#ifndef DD_CONFIGURATION_REDER_H
#define DD_CONFIGURATION_REDER_H
#include <pthread.h>
#include <string.h>
#include "env_config.h"

// this file uses X-Macro concept to render all helpful APIs for automatic setup and cleanup

// define memoization struct
struct ddtrace_memoized_configuration_t {
#define CHAR(getter_name, env_name, default) \
    char* getter_name;                       \
    BOOL_T __is_set_##getter_name;
#define INT(getter_name, env_name, default) \
    int64_t getter_name;                    \
    BOOL_T __is_set_##getter_name;
#define BOOL(getter_name, env_name, default) \
    BOOL_T getter_name;                      \
    BOOL_T __is_set_##getter_name;

    // render configuration struct
    DD_CONFIGURATION

// cleanup macros
#undef CHAR
#undef INT
#undef BOOL
    // configuration mutex
    pthread_mutex_t mutex;
};

// define configuration getters macros
#define CHAR(getter_name, env_name, default)                                       \
    inline static char* getter_name() {                                            \
        if (!ddtrace_memoized_configuration.__is_set_##getter_name) {              \
            pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);             \
            if (!ddtrace_memoized_configuration.__is_set_##getter_name) {          \
                ddtrace_memoized_configuration.getter_name =                       \
                    ddtrace_get_c_string_config_with_default(env_name, default);   \
                ddtrace_memoized_configuration.__is_set_##getter_name = TRUE;      \
            }                                                                      \
            pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);           \
        }                                                                          \
                                                                                   \
        if (ddtrace_memoized_configuration.getter_name) {                          \
            pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);             \
            char* rv = ddtrace_strdup(ddtrace_memoized_configuration.getter_name); \
            pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);           \
            return rv;                                                             \
        } else {                                                                   \
            return NULL;                                                           \
        }                                                                          \
    }

#define INT(getter_name, env_name, default)                                                             \
    inline static int64_t getter_name() {                                                               \
        if (!ddtrace_memoized_configuration.__is_set_##getter_name) {                                   \
            pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);                                  \
            if (!ddtrace_memoized_configuration.__is_set_##getter_name) {                               \
                ddtrace_memoized_configuration.getter_name = ddtrace_get_int_config(env_name, default); \
                ddtrace_memoized_configuration.__is_set_##getter_name = TRUE;                           \
            }                                                                                           \
            pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);                                \
        }                                                                                               \
                                                                                                        \
        return ddtrace_memoized_configuration.getter_name;                                              \
    }

#define BOOL(getter_name, env_name, default)                                                            \
    inline static BOOL_T getter_name() {                                                                \
        if (!ddtrace_memoized_configuration.__is_set_##getter_name) {                                   \
            pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);                                  \
            if (!ddtrace_memoized_configuration.__is_set_##getter_name) {                               \
                ddtrace_memoized_configuration.getter_name = ddtrace_get_int_config(env_name, default); \
                ddtrace_memoized_configuration.__is_set_##getter_name = TRUE;                           \
            }                                                                                           \
            pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);                                \
        }                                                                                               \
                                                                                                        \
        return ddtrace_memoized_configuration.getter_name;                                              \
    }

// render configuration getters
DD_CONFIGURATION

// cleanup configuration getter macros
#undef CHAR
#undef INT
#undef BOOL

#endif  // DD_CONFIGURATION_REDER_H
