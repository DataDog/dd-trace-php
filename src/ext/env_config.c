#include <SAPI.h>
#include "config.h"
#define EQUALS(stra, strb) (memcmp(stra, strb, sizeof(strb) - 1) == 0)

char *get_local_env_or_sapi_env(char *name) {
    TSRMLS_FETCH();
    char *env = NULL, *tmp = getenv(name);
    if (tmp) {
        env = estrdup(tmp);
    } else {
        env = sapi_getenv(name, strlen(name) TSRMLS_CC);
    }

    return env;
}

zend_bool ddtrace_get_bool_config(char *name, zend_bool def) {
    char *env = get_local_env_or_sapi_env(name);
    if (!env) {
        return def;
    }

    size_t len = strlen(env);
    if (len > sizeof("false")) {
        efree(env);
        return def;
    }

    zend_str_tolower(env, len);

    zend_bool rv = def;
    if (EQUALS(env, "1") || EQUALS(env, "true")) {
        rv = 1;
    } else if (EQUALS(env, "0") || EQUALS(env, "false")) {
        rv = 0;
    }

    efree(env);
    return rv;
}

char *ddtrace_get_c_string_config(char *name) {
    char *env = get_local_env_or_sapi_env(name);
    if (!env) {
        return NULL;
    } else {
        return env;
    }
}
