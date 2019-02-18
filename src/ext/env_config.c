#include "config.h"
#include <SAPI.h>
#define EQUALS(stra, strb) (memcmp(stra, strb, sizeof(strb)-1) == 0)

zend_bool ddtrace_get_bool_config(char *name, zend_bool def){
    char *env = sapi_getenv(name, strlen(name));
    if (!env){
        return def;
    }

    size_t len = strlen(env);
    if (len > sizeof("false")){
        return def;
    }

    zend_str_tolower(env, len);

    if (EQUALS(env, "1") || EQUALS(env, "true")) {
        return 1;
    } else if (EQUALS(env, "0") || EQUALS(env, "false")) {
        return 0;
    } else {
        return def;
    }
}
