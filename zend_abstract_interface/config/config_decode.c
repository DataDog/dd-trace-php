#include "config_decode.h"

#include <assert.h>
#include <ctype.h>
#include <main/php.h>
#include <stdbool.h>
#include <string.h>
#include <strings.h>

static bool zai_config_decode_bool(zai_string_view value, zval *decoded_value) {
    if ((value.len == 1 && strcmp(value.ptr, "1") == 0) || (value.len == 2 && strcasecmp(value.ptr, "on") == 0) ||
        (value.len == 3 && strcasecmp(value.ptr, "yes") == 0) ||
        (value.len == 4 && strcasecmp(value.ptr, "true") == 0)) {
        ZVAL_TRUE(decoded_value);
    } else {
        ZVAL_FALSE(decoded_value);
    }

    return true;
}

/* Since strtod() supports conversion of constants like 'NaN' and 'INF', as well
 * as successfully decoding values such as '4foo', we check that only numbers,
 * decimal points, and whitespace exist before passing to strtod().
 */
static bool zai_config_is_valid_double_format(const char *str) {
    bool seen_decimal = false;
    while (*str) {
        if (!isdigit(*str) && !isspace(*str) && *str != '.') return false;
        if (*str == '.') {
            /* strtod will convert '4.2.0' to a double, so we block it. */
            if (seen_decimal) return false;
            seen_decimal = true;
        }
        str++;
    }
    return true;
}

static bool zai_config_decode_double(zai_string_view value, zval *decoded_value) {
    if (!zai_config_is_valid_double_format(value.ptr)) return false;

    const char *endptr = value.ptr;
    double result = zend_strtod(value.ptr, &endptr);

    /* If endptr is not NULL, a pointer to the character after the last
     * character used in the conversion is stored in the location referenced
     * by endptr. If no conversion is performed, zero is returned and the value
     * of nptr is stored in the location referenced by endptr.
     */
    if (endptr != value.ptr) {
        ZVAL_DOUBLE(decoded_value, result);
        return true;
    }
    return false;
}

/* As with strtod(), strtoll() also supports conversion of constants and
 * ill-formatted numbers so we add a number-format check.
 */
static bool zai_config_is_valid_int_format(const char *str) {
    if (!*str) {
        return false;
    }
    while (isspace(*str)) {
        ++str;
    }
    if (*str == '-') {
        ++str;
    }
    while (*str) {
        if (!isdigit(*str) && !isspace(*str)) return false;
        ++str;
    }
    return true;
}

static bool zai_config_decode_int(zai_string_view value, zval *decoded_value) {
    if (!zai_config_is_valid_int_format(value.ptr)) return false;

    ZVAL_LONG(decoded_value, zend_atol(value.ptr, value.len));
    return true;
}

static bool zai_config_decode_map(zai_string_view value, zval *decoded_value, bool persistent) {
    zval tmp;
#if PHP_VERSION_ID < 70000
    INIT_PZVAL(&tmp);
    Z_ARRVAL(tmp) = pemalloc(sizeof(HashTable), persistent);
    Z_TYPE(tmp) = IS_ARRAY;
#else
    ZVAL_ARR(&tmp, pemalloc(sizeof(HashTable), persistent));
#endif
    zend_hash_init(Z_ARRVAL(tmp), 8, NULL, persistent ? ZVAL_INTERNAL_PTR_DTOR : ZVAL_PTR_DTOR, persistent);

    char *data = (char *)value.ptr;
    if (data && *data) {  // non-empty
        const char *key_start, *key_end, *value_start, *value_end;
        do {
            if (*data != ',' && *data != ' ' && *data != '\t' && *data != '\n') {
                key_start = key_end = data;
                while (*++data) {
                    if (*data == ':') {
                        while (*++data && (*data == ' ' || *data == '\t' || *data == '\n'))
                            ;

                        value_start = value_end = data;
                        if (!*data || *data == ',') {
                            --value_end;  // empty string instead of single char
                        } else {
                            while (*++data && *data != ',') {
                                if (*data != ' ' && *data != '\t' && *data != '\n') {
                                    value_end = data;
                                }
                            }
                        }

                        size_t key_len = key_end - key_start + 1;
                        size_t value_len = value_end - value_start + 1;
#if PHP_VERSION_ID < 70000
                        zval *val;
                        if (persistent) {
                            ALLOC_PERMANENT_ZVAL(val);
                        } else {
                            ALLOC_ZVAL(val);
                        }
                        INIT_PZVAL(val);
                        ZVAL_STRINGL(val, pestrndup(value_start, value_len, persistent), value_len, 0);
                        char *zero_terminated_key = pestrndup(key_start, key_len, persistent);
                        zend_hash_add(Z_ARRVAL(tmp), zero_terminated_key, key_len + 1, &val, sizeof(void *), NULL);
                        pefree(zero_terminated_key, persistent);
#else
                        zval val;
                        ZVAL_NEW_STR(&val, zend_string_init(value_start, value_len, persistent));
                        zend_hash_str_add(Z_ARRVAL(tmp), key_start, key_len, &val);
#endif
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

        if (zend_hash_num_elements(Z_ARRVAL(tmp)) == 0) {
            zend_hash_destroy(Z_ARRVAL(tmp));
            pefree(Z_ARRVAL(tmp), persistent);
            return false;
        }
    }

    ZVAL_COPY_VALUE(decoded_value, &tmp);
    return true;
}

static bool zai_config_decode_set(zai_string_view value, zval *decoded_value, bool persistent) {
    zval tmp;
#if PHP_VERSION_ID < 70000
    INIT_PZVAL(&tmp);
    Z_ARRVAL(tmp) = pemalloc(sizeof(HashTable), persistent);
    Z_TYPE(tmp) = IS_ARRAY;
#else
    ZVAL_ARR(&tmp, pemalloc(sizeof(HashTable), persistent));
#endif
    zend_hash_init(Z_ARRVAL(tmp), 8, NULL, NULL, persistent);

    char *data = (char *)value.ptr;
    if (data && *data) {  // non-empty
        const char *key_start, *key_end;
        do {
            if (*data != ',' && *data != ' ' && *data != '\t' && *data != '\n') {
                key_start = key_end = data;
                while (*++data && *data != ',') {
                    if (*data != ' ' && *data != '\t' && *data != '\n') {
                        key_end = data;
                    }
                }
                size_t key_len = key_end - key_start + 1;
#if PHP_VERSION_ID < 70000
                char *zero_terminated_key = pestrndup(key_start, key_len, persistent);
                zend_hash_add_empty_element(Z_ARRVAL(tmp), zero_terminated_key, key_len + 1);
                pefree(zero_terminated_key, persistent);
#else
                zend_hash_str_add_empty_element(Z_ARRVAL(tmp), key_start, key_len);
#endif
            } else {
                ++data;
            }
        } while (*data);

        if (zend_hash_num_elements(Z_ARRVAL(tmp)) == 0) {
            zend_hash_destroy(Z_ARRVAL(tmp));
            pefree(Z_ARRVAL(tmp), persistent);
            return false;
        }
    }

    ZVAL_COPY_VALUE(decoded_value, &tmp);
    return true;
}

static bool zai_config_decode_string(zai_string_view value, zval *decoded_value, bool persistent) {
#if PHP_VERSION_ID < 70000
    ZVAL_STRINGL(decoded_value, pestrndup(value.ptr, value.len, persistent), value.len, 0);
#else
    ZVAL_NEW_STR(decoded_value, zend_string_init(value.ptr, value.len, persistent));
#endif
    return true;
}

bool zai_config_decode_value(zai_string_view value, zai_config_type type, zval *decoded_value, bool persistent) {
    assert((Z_TYPE_P(decoded_value) <= IS_NULL) && "The decoded_value must be IS_UNDEF or IS_NULL");
    switch (type) {
        case ZAI_CONFIG_TYPE_BOOL:
            return zai_config_decode_bool(value, decoded_value);
        case ZAI_CONFIG_TYPE_DOUBLE:
            return zai_config_decode_double(value, decoded_value);
        case ZAI_CONFIG_TYPE_INT:
            return zai_config_decode_int(value, decoded_value);
        case ZAI_CONFIG_TYPE_MAP:
            return zai_config_decode_map(value, decoded_value, persistent);
        case ZAI_CONFIG_TYPE_SET:
            return zai_config_decode_set(value, decoded_value, persistent);
        case ZAI_CONFIG_TYPE_STRING:
            return zai_config_decode_string(value, decoded_value, persistent);
        default:
            assert(false && "Unknown zai_config_type");
    }
    return false;
}
