#include "configuration_php_iface.h"

#include <php.h>

#include "compatibility.h"
#include "configuration.h"

// eventually this will need to be rewritten to use hashmap populated at startup to perform lookup for performance
// reasons however for low cardinality of envs of the same name this should be fast enough
#define ENV_NAME_MATCHES(env_name, _env_name, _env_len) \
    ((sizeof(env_name) - 1) == _env_len && strncmp(_env_name, env_name, _env_len) == 0)

// forward declarations
BOOL_T get_configuration(zval *return_value, char *env_name, size_t env_name_len);
size_t convert_cfg_id_to_envname(char **envname_p, char *id, size_t id_length);

// implementations

void ddtrace_php_get_configuration(zval *return_value, zval *zenv_name) {
    char *env_name = Z_STRVAL_P(zenv_name);
    size_t env_name_len = Z_STRLEN_P(zenv_name);
    if (env_name_len == 0 && env_name) {
        env_name_len = strlen(env_name);
    }
    if (env_name_len == 0) {
        RETURN_NULL();
    }

    if (get_configuration(return_value, env_name, env_name_len)) {
        return;
    } else {
        char *tmp_envname = NULL;
        size_t tmp_envname_len = convert_cfg_id_to_envname(&tmp_envname, env_name, env_name_len);
        if (env_name_len > 0 && tmp_envname) {
            if (!get_configuration(return_value, tmp_envname, tmp_envname_len)) {
                RETVAL_NULL();
            }
            free(tmp_envname);
            return;
        } else {
            if (tmp_envname) {
                free(tmp_envname);
            }
            RETURN_NULL();
        }
    }
}

#define ID_TO_ENV_PREFIX "DD_"

size_t convert_cfg_id_to_envname(char **envname_p, char *id, size_t id_length) {
    if (id_length == 0) {
        return 0;
    }

    size_t envname_length = id_length + sizeof(ID_TO_ENV_PREFIX) - 1;
    char *envname = calloc(1, envname_length + sizeof('\0'));
    *envname_p = envname;

    if (!envname) {
        return 0;
    }

    if (snprintf(envname, envname_length + sizeof('\0'), ID_TO_ENV_PREFIX "%s", id) <= 0) {
        free(envname);
        return 0;
    }

    char *cptr = envname;
    while (*cptr && (size_t)(cptr - envname) < envname_length) {
        if (*cptr == '.') {
            *cptr = '_';
        } else {
            *cptr = toupper((unsigned char)*cptr);
        }
        cptr++;
    }

    return envname_length;
}

BOOL_T get_configuration(zval *return_value, char *env_name, size_t env_name_len) {
#define CHAR(getter, env, ...)                               \
    do {                                                     \
        if (ENV_NAME_MATCHES(env, env_name, env_name_len)) { \
            char *str = getter();                            \
            if (str) {                                       \
                COMPAT_RETVAL_STRING(str);                   \
                free(str);                                   \
            } else {                                         \
                RETVAL_NULL();                               \
            }                                                \
            return TRUE;                                     \
        }                                                    \
    } while (0);

#define BOOL(getter, env, ...)                               \
    do {                                                     \
        if (ENV_NAME_MATCHES(env, env_name, env_name_len)) { \
            RETVAL_BOOL(getter());                           \
            return TRUE;                                     \
        }                                                    \
    } while (0);

#define INT(getter, env, ...)                                \
    do {                                                     \
        if (ENV_NAME_MATCHES(env, env_name, env_name_len)) { \
            RETVAL_LONG(getter());                           \
            return TRUE;                                     \
        }                                                    \
    } while (0);

    DD_CONFIGURATION

    // did not match any configuration getter
    return FALSE;
}
