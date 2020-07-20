#include "configuration.h"

#include <stdlib.h>

#include "ddtrace_string.h"
#include "env_config.h"
#include "integrations/integrations.h"

extern inline ddtrace_string ddtrace_string_getenv(char* str, size_t len TSRMLS_DC);
extern inline ddtrace_string ddtrace_string_getenv_multi(char* primary, size_t primary_len, char* secondary,
                                                         size_t secondary_len TSRMLS_DC);

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
    if (subject.len == 1) {
        if (strcmp(subject.ptr, "1") == 0) {
            return true;
        } else if (strcmp(subject.ptr, "0") == 0) {
            return false;
        }
    } else if ((subject.len == 4 && strcasecmp(subject.ptr, "true") == 0)) {
        return true;
    } else if ((subject.len == 5 && strcasecmp(subject.ptr, "false") == 0)) {
        return false;
    }
    return default_value;
}

bool ddtrace_config_env_bool(ddtrace_string env_name, bool default_value TSRMLS_DC) {
    ddtrace_string env_val = ddtrace_string_getenv(env_name.ptr, env_name.len TSRMLS_CC);
    bool result = default_value;
    if (env_val.len) {
        result = ddtrace_config_bool(env_val, default_value);
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

// Get env name for <PREFIX_><INTEGRATION><_SUFFIX> (i.e. <DD_TRACE_><LARAVEL><_ENABLED>)
size_t ddtrace_config_integration_env_name(char* name, const char* prefix, ddtrace_integration* integration,
                                           const char* suffix) {
#if PHP_VERSION_ID >= 70000
    ZEND_ASSERT(strlen(prefix) <= DDTRACE_LONGEST_INTEGRATION_ENV_PREFIX_LEN);
    ZEND_ASSERT(strlen(suffix) <= DDTRACE_LONGEST_INTEGRATION_ENV_SUFFIX_LEN);
#endif
    return (size_t)snprintf(name, DDTRACE_LONGEST_INTEGRATION_ENV_LEN, "%s%s%s", prefix, integration->name_ucase,
                            suffix);
}

// Get env value for <PREFIX_><INTEGRATION><_SUFFIX>
ddtrace_string _dd_env_integration_value(const char* prefix, ddtrace_integration* integration,
                                         const char* suffix TSRMLS_DC) {
    char name[DDTRACE_LONGEST_INTEGRATION_ENV_LEN];
    size_t len = ddtrace_config_integration_env_name(name, prefix, integration, suffix);
    return ddtrace_string_getenv(name, len TSRMLS_CC);
}

#define DD_INTEGRATION_ENABLED_DEFAULT true

// note: only call this if ddtrace_config_trace_enabled() returns true
bool ddtrace_config_integration_enabled(ddtrace_string integration_str TSRMLS_DC) {
    ddtrace_integration* integration = ddtrace_get_integration_from_string(integration_str);
    if (integration == NULL) {
        return DD_INTEGRATION_ENABLED_DEFAULT;
    }
    return ddtrace_config_integration_enabled_ex(integration->name TSRMLS_CC);
}

bool ddtrace_config_integration_enabled_ex(ddtrace_integration_name integration_name TSRMLS_DC) {
    bool result = DD_INTEGRATION_ENABLED_DEFAULT;
    ddtrace_integration* integration = &ddtrace_integrations[integration_name];

    ddtrace_string env_val = _dd_env_integration_value("DD_TRACE_", integration, "_ENABLED" TSRMLS_CC);
    if (env_val.len) {
        result = ddtrace_config_bool(env_val, result);
        efree(env_val.ptr);
        return result;
    }
    if (env_val.ptr) {
        efree(env_val.ptr);
    }

    // Deprecated as of 0.47.1
    env_val = ddtrace_string_getenv(ZEND_STRL("DD_INTEGRATIONS_DISABLED") TSRMLS_CC);
    if (env_val.len) {
        ddtrace_string tmp;
        tmp.ptr = integration->name_lcase;
        tmp.len = integration->name_len;
        result = !ddtrace_string_contains_in_csv(env_val, tmp);
    }
    if (env_val.ptr) {
        efree(env_val.ptr);
    }
    return result;
}

#define DD_INTEGRATION_ANALYTICS_ENABLED_DEFAULT false

bool ddtrace_config_integration_analytics_enabled(ddtrace_string integration_str TSRMLS_DC) {
    ddtrace_integration* integration = ddtrace_get_integration_from_string(integration_str);
    if (integration == NULL) {
        return DD_INTEGRATION_ANALYTICS_ENABLED_DEFAULT;
    }
    return ddtrace_config_integration_analytics_enabled_ex(integration->name TSRMLS_CC);
}

bool ddtrace_config_integration_analytics_enabled_ex(ddtrace_integration_name integration_name TSRMLS_DC) {
    bool result = DD_INTEGRATION_ANALYTICS_ENABLED_DEFAULT;
    ddtrace_integration* integration = &ddtrace_integrations[integration_name];
    ddtrace_string env_val;

    env_val = _dd_env_integration_value("DD_TRACE_", integration, "_ANALYTICS_ENABLED" TSRMLS_CC);
    if (env_val.len) {
        result = ddtrace_config_bool(env_val, result);
        efree(env_val.ptr);
        return result;
    }
    if (env_val.ptr) {
        efree(env_val.ptr);
    }

    // Deprecated as of 0.47.1
    env_val = _dd_env_integration_value("DD_", integration, "_ANALYTICS_ENABLED" TSRMLS_CC);
    if (env_val.len) {
        result = ddtrace_config_bool(env_val, result);
        efree(env_val.ptr);
        return result;
    }
    if (env_val.ptr) {
        efree(env_val.ptr);
    }
    return result;
}

#define DD_INTEGRATION_ANALYTICS_SAMPLE_RATE_DEFAULT 1.0

double ddtrace_config_integration_analytics_sample_rate(ddtrace_string integration_str TSRMLS_DC) {
    ddtrace_integration* integration = ddtrace_get_integration_from_string(integration_str);
    if (integration == NULL) {
        return DD_INTEGRATION_ANALYTICS_SAMPLE_RATE_DEFAULT;
    }
    return ddtrace_config_integration_analytics_sample_rate_ex(integration->name TSRMLS_CC);
}

double ddtrace_config_integration_analytics_sample_rate_ex(ddtrace_integration_name integration_name TSRMLS_DC) {
    double result = DD_INTEGRATION_ANALYTICS_SAMPLE_RATE_DEFAULT;
    ddtrace_integration* integration = &ddtrace_integrations[integration_name];
    ddtrace_string env_val;

    env_val = _dd_env_integration_value("DD_TRACE_", integration, "_ANALYTICS_SAMPLE_RATE" TSRMLS_CC);
    if (env_val.len) {
        result = ddtrace_char_to_double(env_val.ptr, result);
        efree(env_val.ptr);
        return result;
    }
    if (env_val.ptr) {
        efree(env_val.ptr);
    }

    // Deprecated as of 0.47.1
    env_val = _dd_env_integration_value("DD_", integration, "_ANALYTICS_SAMPLE_RATE" TSRMLS_CC);
    if (env_val.len) {
        result = ddtrace_char_to_double(env_val.ptr, result);
        efree(env_val.ptr);
        return result;
    }
    if (env_val.ptr) {
        efree(env_val.ptr);
    }
    return result;
}

bool ddtrace_config_trace_enabled(TSRMLS_D) {
    ddtrace_string env_name = DDTRACE_STRING_LITERAL("DD_TRACE_ENABLED");
    return ddtrace_config_env_bool(env_name, true TSRMLS_CC);
}
