#ifndef DD_CONFIGURATION_RENDER_H
#define DD_CONFIGURATION_RENDER_H
#include <pthread.h>
#include <string.h>

#include "env_config.h"

// this file uses X-Macro concept to render all helpful APIs for automatic setup and cleanup

// define memoization struct
struct ddtrace_memoized_configuration_t {
#define CHAR(getter_name, env_name, default, ...) \
    char *getter_name;                            \
    bool __is_set_##getter_name;
#define HASH(getter_name, env_name, ...) \
    zend_array *getter_name;             \
    bool __is_set_##getter_name;
#define BOOL(getter_name, env_name, default, ...) \
    bool getter_name;                             \
    bool __is_set_##getter_name;
#define INT(getter_name, env_name, default, ...) \
    int64_t getter_name;                         \
    bool __is_set_##getter_name;
#define DOUBLE(getter_name, env_name, default, ...) \
    double getter_name;                             \
    double __is_set_##getter_name;

    // render configuration struct
    DD_CONFIGURATION

// cleanup macros
#undef CHAR
#undef HASH
#undef BOOL
#undef INT
#undef DOUBLE
    // configuration mutex
    pthread_mutex_t mutex;
};

// define configuration getters macros
#define CHAR(getter_name, env_name, default, ...)                                      \
    inline char *getter_name(void) {                                                   \
        if (ddtrace_memoized_configuration.__is_set_##getter_name) {                   \
            if (ddtrace_memoized_configuration.getter_name) {                          \
                pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);             \
                char *rv = ddtrace_strdup(ddtrace_memoized_configuration.getter_name); \
                pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex);           \
                return rv;                                                             \
            } else {                                                                   \
                return NULL;                                                           \
            }                                                                          \
        } else {                                                                       \
            if (default) {                                                             \
                return ddtrace_strdup(default);                                        \
            } else {                                                                   \
                return NULL;                                                           \
            }                                                                          \
        }                                                                              \
    }

#define HASH(getter_name, env_name, ...)                                     \
    static inline zend_array *getter_name(void) {                            \
        if (ddtrace_memoized_configuration.__is_set_##getter_name) {         \
            if (ddtrace_memoized_configuration.getter_name) {                \
                pthread_mutex_lock(&ddtrace_memoized_configuration.mutex);   \
                zend_array *rv = ddtrace_memoized_configuration.getter_name; \
                GC_ADDREF(rv);                                               \
                pthread_mutex_unlock(&ddtrace_memoized_configuration.mutex); \
                return rv;                                                   \
            }                                                                \
        }                                                                    \
        return (zend_array *)&zend_empty_array;                              \
    }

#define BOOL(getter_name, env_name, default, ...)                    \
    inline bool getter_name(void) {                                  \
        if (ddtrace_memoized_configuration.__is_set_##getter_name) { \
            return ddtrace_memoized_configuration.getter_name;       \
        } else {                                                     \
            return true;                                             \
        }                                                            \
    }

#define INT(getter_name, env_name, default, ...)                     \
    inline int64_t getter_name(void) {                               \
        if (ddtrace_memoized_configuration.__is_set_##getter_name) { \
            return ddtrace_memoized_configuration.getter_name;       \
        } else {                                                     \
            return default;                                          \
        }                                                            \
    }

#define DOUBLE(getter_name, env_name, default, ...)                  \
    inline double getter_name(void) {                                \
        if (ddtrace_memoized_configuration.__is_set_##getter_name) { \
            return ddtrace_memoized_configuration.getter_name;       \
        } else {                                                     \
            return default;                                          \
        }                                                            \
    }

// render configuration getters
DD_CONFIGURATION

// cleanup configuration getter macros
#undef CHAR
#undef HASH
#undef BOOL
#undef INT
#undef DOUBLE

#endif  // DD_CONFIGURATION_RENDER_H
