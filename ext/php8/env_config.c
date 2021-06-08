// clang-format off
#include <php.h>
#include "compatibility.h"

#include "env_config.h"
// clang-format on

#include <SAPI.h>
#include <Zend/zend_types.h>
#include <php.h>
#include <stdint.h>

#include "coms.h"

#define EQUALS(stra, stra_len, literal_strb) \
    (stra_len == (sizeof(literal_strb) - 1) && memcmp(stra, literal_strb, sizeof(literal_strb) - 1) == 0)

char *ddtrace_getenv(char *name, size_t name_len) {
    char *env = sapi_getenv(name, name_len);
    if (env) {
        return env;
    }
    env = getenv(name);
    return env ? estrdup(env) : NULL;
}

char *ddtrace_getenv_multi(char *primary, size_t primary_len, char *secondary, size_t secondary_len) {
    // Primary env name, if exists
    char *env = ddtrace_getenv(primary, primary_len);
    if (env) {
        return env;
    }
    // Otherwise we use the secondary env name
    return ddtrace_getenv(secondary, secondary_len);
}

char *get_local_env_or_sapi_env(char *name) {
    char *env = NULL;
    // reading sapi_getenv from within writer thread can and will lead to undefined behaviour
    if (!ddtrace_in_writer_thread()) {
        env = sapi_getenv(name, strlen(name));
        if (env) {
            // convert PHP memory to pure C memory since this could be used in non request contexts too
            // currently we're not using permanent C memory anywhere, while this could be applied here
            // it seems more practical to simply use "C memory" instead of having 3rd way to free and allocate memory
            char *tmp = ddtrace_strdup(env);
            efree(env);
            return tmp;
        }
    }

    env = getenv(name);
    return env ? ddtrace_strdup(env) : NULL;
}

BOOL_T ddtrace_get_bool_config(char *name, BOOL_T def) {
    char *env = get_local_env_or_sapi_env(name);
    if (!env) {
        return def;
    }

    size_t len = strlen(env);
    if (len > sizeof("false")) {
        free(env);
        return def;
    }

    zend_str_tolower(env, len);

    zend_bool rv = def;
    if (EQUALS(env, len, "1") || EQUALS(env, len, "true")) {
        rv = 1;
    } else if (EQUALS(env, len, "0") || EQUALS(env, len, "false")) {
        rv = 0;
    }

    free(env);
    return rv;
}

int64_t ddtrace_get_int_config(char *name, int64_t def) {
    char *env = get_local_env_or_sapi_env(name);
    if (!env) {
        return def;
    }

    char *endptr = env;

    long long result = strtoll(env, &endptr, 10);

    if (endptr == env) {
        free(env);

        return def;
    }
    free(env);

    return result;
}

uint32_t ddtrace_get_uint32_config(char *name, uint32_t def) {
    int64_t value = ddtrace_get_int_config(name, def);
    if (value < 0 || value > UINT32_MAX) {
        value = def;
    }
    return value;
}

double ddtrace_get_double_config(char *name, double def) {
    char *env = get_local_env_or_sapi_env(name);
    if (!env) {
        return def;
    }
    double result = ddtrace_char_to_double(env, def);
    free(env);
    return result;
}

double ddtrace_char_to_double(char *subject, double default_value) {
    char *endptr = subject;

    // The strtod function is a bit tricky, so I've quoted docs to explain code

    /* Since 0 can legitimately be returned on both success and failure, the
     * calling program should set errno to 0 before the call, and then
     * determine if an error occurred by checking whether errno has a nonzero
     * value after the call.
     */
    errno = 0;
    double result = strtod(subject, &endptr);

    /* If endptr is not NULL, a pointer to the character after the last
     * character used in the conversion is stored in the location referenced
     * by endptr. If no conversion is performed, zero is returned and the value
     * of nptr is stored in the location referenced by endptr.
     */
    int conversion_performed = endptr != subject && errno == 0;

    return conversion_performed ? result : default_value;
}

char *ddtrace_get_c_string_config(char *name) {
    char *env = get_local_env_or_sapi_env(name);
    if (!env) {
        return NULL;
    } else {
        return env;
    }
}

char *ddtrace_get_c_string_config_with_default(char *name, const char *def) {
    char *env = get_local_env_or_sapi_env(name);
    if (!env) {
        if (def) {
            return ddtrace_strdup(def);
        } else {
            return NULL;
        }
    } else {
        return env;
    }
}

// Do not use regular strdup! On some platforms it segfaults with C11.
char *ddtrace_strdup(const char *source) {
    size_t size = strlen(source) + 1;
    char *dest = malloc(size);
    if (dest) {
        memcpy(dest, source, size);
    }
    return dest;
}

static void ddtrace_config_str_dtor(zval *zval_ptr) {
    ZEND_ASSERT(Z_TYPE_P(zval_ptr) == IS_STRING);
    zend_string_release_ex(Z_STR_P(zval_ptr), 1);
}

zend_array *ddtrace_parse_c_string_to_hash(const char *data) {
    zend_array *array = malloc(sizeof(*array));
    _zend_hash_init(array, 0, ddtrace_config_str_dtor, 1);
    if (data && *data) {  // non-empty
        const char *key_start, *key_end, *value_start, *value_end;
        do {
            if (*data != ',' && *data != ' ' && *data != '\t' && *data != '\n') {
                key_start = key_end = data;
                while (*++data) {
                    if (*data == ':') {
                        while (*++data && (*data == ' ' || *data == '\t' || *data == '\n'))
                            ;

                        if (!*data) {
                            break;
                        }

                        value_start = value_end = data;
                        if (*data == ',') {
                            --value_end;  // empty string instead of single char
                        } else {
                            while (*++data && *data != ',') {
                                if (*data != ' ' && *data != '\t' && *data != '\n') {
                                    value_end = data;
                                }
                            }
                        }

                        zval val;
                        zend_string *key = zend_string_init(key_start, key_end - key_start + 1, 1);
                        ZVAL_PSTRINGL(&val, value_start, value_end - value_start + 1);
                        zend_hash_add(array, key, &val);
                        zend_string_release(key);

                        break;
                    }
                    if (*data != ' ' && *data != '\t' && *data != '\n') {
                        key_end = data;
                    }
                }
            } else {
                ++data;
            }
        } while (*data);
    }
    return array;
}

// Note that these arrays shall never be touched, except GC_ADDREF() and zend_hash_release() to avoid non-atomic
// operations causing possible race conditions
zend_array *ddtrace_get_hash_config(char *name) {
    char *str = ddtrace_get_c_string_config_with_default(name, "");
    zend_array *array = ddtrace_parse_c_string_to_hash(str);
    free(str);
    return array;
}
