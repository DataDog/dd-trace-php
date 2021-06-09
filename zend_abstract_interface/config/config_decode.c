#include "config_decode.h"

#include <assert.h>
#include <ctype.h>
#include <main/php.h>
#include <stdbool.h>
#include <string.h>
#include <strings.h>

#include "config.h"

static bool zai_config_decode_bool(zai_string_view value, zval *decoded_value) {
    if (value.len == 1) {
        if (strcmp(value.ptr, "1") == 0) {
            ZVAL_TRUE(decoded_value);
        } else if (strcmp(value.ptr, "0") == 0) {
            ZVAL_FALSE(decoded_value);
        }
    } else if ((value.len == 4 && strcasecmp(value.ptr, "true") == 0)) {
        ZVAL_TRUE(decoded_value);
    } else if ((value.len == 5 && strcasecmp(value.ptr, "false") == 0)) {
        ZVAL_FALSE(decoded_value);
    }
    return Z_TYPE_P(decoded_value) == IS_TRUE || Z_TYPE_P(decoded_value) == IS_FALSE;
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

    char *endptr = (char *)value.ptr;

    // The strtod function is a bit tricky, so I've quoted docs to explain code

    /* Since 0 can legitimately be returned on both success and failure, the
     * calling program should set errno to 0 before the call, and then
     * determine if an error occurred by checking whether errno has a nonzero
     * value after the call.
     */
    errno = 0;
    double result = strtod(value.ptr, &endptr);

    /* If endptr is not NULL, a pointer to the character after the last
     * character used in the conversion is stored in the location referenced
     * by endptr. If no conversion is performed, zero is returned and the value
     * of nptr is stored in the location referenced by endptr.
     */
    if (endptr != value.ptr && errno == 0) {
        ZVAL_DOUBLE(decoded_value, result);
        return true;
    }
    return false;
}

/* As with strtod(), strtoll() also supports conversion of constants and
 * ill-formatted numbers so we add a number-format check.
 */
static bool zai_config_is_valid_int_format(const char *str) {
    while (*str) {
        if (!isdigit(*str) && !isspace(*str)) return false;
        str++;
    }
    return true;
}

static bool zai_config_decode_int(zai_string_view value, zval *decoded_value) {
    if (!zai_config_is_valid_int_format(value.ptr)) return false;

    char *endptr = (char *)value.ptr;

    errno = 0;
    long long int result = strtoll(value.ptr, &endptr, 10);

    if (endptr != value.ptr && errno == 0) {
        ZVAL_LONG(decoded_value, result);
        return true;
    }
    return false;
}

static void zai_config_pstr_dtor(zval *zval_ptr) {
    ZEND_ASSERT(Z_TYPE_P(zval_ptr) == IS_STRING);
    zend_string_release_ex(Z_STR_P(zval_ptr), 1);
}

static bool zai_config_decode_map(zai_string_view value, zval *decoded_value, bool persistent) {
    zval tmp;
    if (persistent) {
        HashTable *ht = malloc(sizeof(HashTable));
        zend_hash_init(ht, 8, NULL, zai_config_pstr_dtor, /* persistent */ 1);
        ZVAL_ARR(&tmp, ht);
    } else {
        array_init(&tmp);
    }

    // TODO Extract this
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
                        zend_string *key = zend_string_init(key_start, key_end - key_start + 1, persistent);
                        if (persistent) {
                            ZVAL_PSTRINGL(&val, value_start, value_end - value_start + 1);
                        } else {
                            ZVAL_STRINGL(&val, value_start, value_end - value_start + 1);
                        }
                        zend_hash_add(Z_ARRVAL(tmp), key, &val);
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

        if (zend_hash_num_elements(Z_ARRVAL(tmp)) == 0) {
            if (persistent) {
                zend_hash_destroy(Z_ARRVAL(tmp));
                free(Z_ARRVAL(tmp));
            } else {
                zval_ptr_dtor(&tmp);
            }
            return false;
        }
    }

    ZVAL_COPY_VALUE(decoded_value, &tmp);
    return true;
}

static bool zai_config_decode_string(zai_string_view value, zval *decoded_value, bool persistent) {
    if (persistent) {
        ZVAL_PSTRINGL(decoded_value, value.ptr, value.len);
    } else {
        ZVAL_STRINGL(decoded_value, value.ptr, value.len);
    }
    return true;
}

bool zai_config_decode_value(zai_string_view value, zai_config_type type, zval *decoded_value, bool persistent) {
    assert((Z_TYPE_P(decoded_value) == IS_UNDEF) && "The decoded_value must be IS_UNDEF");
    switch (type) {
        case ZAI_CONFIG_TYPE_BOOL:
            return zai_config_decode_bool(value, decoded_value);
        case ZAI_CONFIG_TYPE_DOUBLE:
            return zai_config_decode_double(value, decoded_value);
        case ZAI_CONFIG_TYPE_INT:
            return zai_config_decode_int(value, decoded_value);
        case ZAI_CONFIG_TYPE_MAP:
            return zai_config_decode_map(value, decoded_value, persistent);
        case ZAI_CONFIG_TYPE_STRING:
            return zai_config_decode_string(value, decoded_value, persistent);
    }
    assert(false && "Unknown zai_config_type");
    return false;
}
