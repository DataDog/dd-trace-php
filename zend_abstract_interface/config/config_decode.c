#include "config_decode.h"

#include <assert.h>
#include <ctype.h>
#include <json/json.h>
#include <main/php.h>
#include <stdbool.h>
#include <string.h>

static bool zai_config_decode_bool(zai_str value, zval *decoded_value) {
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
    while (isspace(*str)) {
        ++str;
    }
    if (*str == '-') {
        ++str;
    }
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

static bool zai_config_decode_double(zai_str value, zval *decoded_value) {
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

static bool zai_config_decode_int(zai_str value, zval *decoded_value) {
    if (!zai_config_is_valid_int_format(value.ptr)) return false;

#if PHP_VERSION_ID >= 80200
    zend_string *zstr = zend_string_init(value.ptr, value.len, 0);
    zend_string *err = NULL;
    zend_long l = zend_ini_parse_quantity(zstr, &err);

    zend_string_free(zstr);
    if (err) {
        /* Strings are supposed to be checked by zai_config_is_valid_int_format
         * already, so we do not expect to hit any errors here.
         */
        zend_string_release(err);
        return false;
    }
#else
    zend_long l = zend_atol(value.ptr, value.len);
#endif

    ZVAL_LONG(decoded_value, l);
    return true;
}

static bool zai_config_decode_map(zai_str value, zval *decoded_value, bool persistent, bool lowercase, bool map_keyless) {
    zval tmp;
    ZVAL_ARR(&tmp, pemalloc(sizeof(HashTable), persistent));
    zend_hash_init(Z_ARRVAL(tmp), 8, NULL, persistent ? ZVAL_INTERNAL_PTR_DTOR : ZVAL_PTR_DTOR, persistent);

    char *data = (char *)value.ptr;
    if (data && *data) {  // non-empty
        const char *key_start, *key_end, *value_start, *value_end;

        do {
            while (*data == ',' || *data == ' ' || *data == '\t' || *data == '\n') {
                data++;
            }

            if (*data) {
                key_start = data;
                key_end = NULL;
                bool has_colon = false;

                while (*data && *data != ',') {
                    if (*data == ':') {
                        has_colon = true;
                        data++;
                        
                        while (*data == ' ' || *data == '\t' || *data == '\n') data++;

                        if (*data == ',' || !*data) {
                            value_end = NULL;
                        } else {
                            value_start = value_end = data;
                            do {
                                if (*data != ' ' && *data != '\t' && *data != '\n') {
                                    value_end = data;
                                }
                                data++;
                            } while (*data && *data != ',');
                        }

                        if (key_end && key_start) {
                            size_t key_len = key_end - key_start + 1;

                            zend_string *key = zend_string_init(key_start, key_len, persistent);
                            if (lowercase) {
                                zend_str_tolower(ZSTR_VAL(key), ZSTR_LEN(key));
                            }

                            zval val;
                            if (value_end) {
                                size_t value_len = value_end - value_start + 1;
                                ZVAL_NEW_STR(&val, zend_string_init(value_start, value_len, persistent));
                            } else {
                                if (persistent) {
                                    ZVAL_EMPTY_PSTRING(&val);
                                } else {
                                    ZVAL_EMPTY_STRING(&val);
                                }
                            }
                            zend_hash_update(Z_ARRVAL(tmp), key, &val);
                            zend_string_release(key);
                        }

                        break;
                    }

                    // Set key_end to the last valid non-whitespace character of the key
                    if (*data != ' ' && *data != '\t' && *data != '\n') {
                        key_end = data;
                    }
                    data++;
                }

                // Handle standalone keys (without a colon) if map_keyless is enabled
                if (map_keyless && !has_colon && key_end) {
                    size_t key_len = key_end - key_start + 1;
                    zend_string *key = zend_string_init(key_start, key_len, persistent);
                    if (lowercase) {
                        zend_str_tolower(ZSTR_VAL(key), ZSTR_LEN(key));
                    }

                    zval val;
                    if (persistent) {
                        ZVAL_EMPTY_PSTRING(&val);
                    } else {
                        ZVAL_EMPTY_STRING(&val);
                    }
                    zend_hash_update(Z_ARRVAL(tmp), key, &val);
                    zend_string_release(key);
                }
            }
        } while (*data);

        // Check if the array has any elements; if not, cleanup
        if (zend_hash_num_elements(Z_ARRVAL(tmp)) == 0) {
            zend_hash_destroy(Z_ARRVAL(tmp));
            pefree(Z_ARRVAL(tmp), persistent);
            return false;
        }
    }

    ZVAL_COPY_VALUE(decoded_value, &tmp);
    return true;
}

static bool zai_config_decode_set(zai_str value, zval *decoded_value, bool persistent, bool lowercase) {
    zval tmp;
    ZVAL_ARR(&tmp, pemalloc(sizeof(HashTable), persistent));
    zend_hash_init(Z_ARRVAL(tmp), 8, NULL, persistent ? ZVAL_INTERNAL_PTR_DTOR : ZVAL_PTR_DTOR, persistent);

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
                zend_string *key = zend_string_init(key_start, key_len, persistent);
                if (lowercase) {
                    zend_str_tolower(ZSTR_VAL(key), ZSTR_LEN(key));
                }
                zend_hash_add_empty_element(Z_ARRVAL(tmp), key);
                zend_string_release(key);
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

static bool zai_config_decode_json(zai_str value, zval *decoded_value, bool persistent) {
    if (zai_json_decode_assoc_safe(decoded_value, (char *)value.ptr, (int)value.len, 20, persistent) != SUCCESS) {
        ZVAL_NULL(decoded_value);
        return false;
    }

    if (Z_TYPE_P(decoded_value) != IS_ARRAY) {
        if (persistent) {
            zval_internal_ptr_dtor(decoded_value);
        } else {
            zval_dtor(decoded_value);
        }
        ZVAL_NULL(decoded_value);
        return false;
    }

    return true;
}

static bool zai_config_decode_string(zai_str value, zval *decoded_value, bool persistent) {
    ZVAL_NEW_STR(decoded_value, zend_string_init(value.ptr, value.len, persistent));
    return true;
}

bool zai_config_decode_value(zai_str value, zai_config_type type, zai_custom_parse custom_parser, zval *decoded_value, bool persistent) {
    assert((Z_TYPE_P(decoded_value) <= IS_NULL) && "The decoded_value must be IS_UNDEF or IS_NULL");
    switch (type) {
        case ZAI_CONFIG_TYPE_BOOL:
            return zai_config_decode_bool(value, decoded_value);
        case ZAI_CONFIG_TYPE_DOUBLE:
            return zai_config_decode_double(value, decoded_value);
        case ZAI_CONFIG_TYPE_INT:
            return zai_config_decode_int(value, decoded_value);
        case ZAI_CONFIG_TYPE_MAP:
            return zai_config_decode_map(value, decoded_value, persistent, false, false);
        case ZAI_CONFIG_TYPE_SET_OR_MAP_LOWERCASE:
            return zai_config_decode_map(value, decoded_value, persistent, true, true);
        case ZAI_CONFIG_TYPE_SET:
            return zai_config_decode_set(value, decoded_value, persistent, false);
        case ZAI_CONFIG_TYPE_SET_LOWERCASE:
            return zai_config_decode_set(value, decoded_value, persistent, true);
        case ZAI_CONFIG_TYPE_JSON:
            return zai_config_decode_json(value, decoded_value, persistent);
        case ZAI_CONFIG_TYPE_STRING:
            return zai_config_decode_string(value, decoded_value, persistent);
        case ZAI_CONFIG_TYPE_CUSTOM:
            return custom_parser(value, decoded_value, persistent);
        default:
            assert(false && "Unknown zai_config_type");
    }
    return false;
}
