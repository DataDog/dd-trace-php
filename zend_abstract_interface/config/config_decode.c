#include "config_decode.h"

#include <assert.h>
#include <ctype.h>
#include <json/json.h>
#include <main/php.h>
#include <stdbool.h>
#include <string.h>
#include <strings.h>
#include <zai_compat.h>

#if PHP_VERSION_ID < 70000
#undef zval_internal_ptr_dtor
#define zval_internal_ptr_dtor zval_internal_dtor
#define ZVAL_UNDEF(z)  \
    {                  \
        INIT_PZVAL(z); \
        ZVAL_NULL(z);  \
    }
static void zai_config_dtor_ppzval(void *ptr) {
    zval **zval_ptr = ptr;
    Z_DELREF_PP(zval_ptr);
    if (Z_REFCOUNT_PP(zval_ptr) == 0) {
        zai_config_dtor_pzval(*zval_ptr);
        free(*zval_ptr);
    } else if (Z_REFCOUNT_PP(zval_ptr) == 1) {
        Z_UNSET_ISREF_PP(zval_ptr);
    }
}
#endif

#if PHP_VERSION_ID >= 70000 && PHP_VERSION_ID < 80000
#if PHP_VERSION_ID < 70300
#define GC_DELREF(x) (--GC_REFCOUNT(x))
#endif

static zend_always_inline void zend_hash_release(zend_array *array) {
    if (!(GC_FLAGS(array) & IS_ARRAY_IMMUTABLE)) {
        if (GC_DELREF(array) == 0) {
            zend_hash_destroy(array);
#if PHP_VERSION_ID < 70300
            pefree(array, array->u.flags & HASH_FLAG_PERSISTENT);
#else
            pefree(array, GC_FLAGS(array) & IS_ARRAY_PERSISTENT);
#endif
        }
    }
}
#endif

void zai_config_dtor_pzval(zval *pval) {
    if (Z_TYPE_P(pval) == IS_ARRAY) {
        if (Z_DELREF_P(pval) == 0) {
            zend_hash_destroy(Z_ARRVAL_P(pval));
            free(Z_ARRVAL_P(pval));
        }
    } else {
        zval_internal_ptr_dtor(pval);
    }
    // Prevent an accidental use after free
    ZVAL_UNDEF(pval);
}

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
                        zend_hash_update(Z_ARRVAL(tmp), zero_terminated_key, key_len + 1, &val, sizeof(void *), NULL);
                        pefree(zero_terminated_key, persistent);
#else
                        zval val;
                        ZVAL_NEW_STR(&val, zend_string_init(value_start, value_len, persistent));
                        zend_hash_str_update(Z_ARRVAL(tmp), key_start, key_len, &val);
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

static bool zai_config_decode_set(zai_string_view value, zval *decoded_value, bool persistent, bool lowercase) {
    zval tmp;
#if PHP_VERSION_ID < 70000
    INIT_PZVAL(&tmp);
    zval *nullval = NULL;
    Z_ARRVAL(tmp) = pemalloc(sizeof(HashTable), persistent);
    Z_TYPE(tmp) = IS_ARRAY;
#else
    ZVAL_ARR(&tmp, pemalloc(sizeof(HashTable), persistent));
#endif
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
#if PHP_VERSION_ID < 70000
                char *zero_terminated_key = pemalloc(key_len + 1, persistent);
                if (lowercase) {
                    zend_str_tolower_copy(zero_terminated_key, key_start, key_len);
                } else {
                    memcpy(zero_terminated_key, key_start, key_len);
                    zero_terminated_key[key_len] = 0;
                }
                if (nullval) {
                    zval_addref_p(nullval);
                } else {
                    if (persistent) {
                        ALLOC_PERMANENT_ZVAL(nullval);
                    } else {
                        ALLOC_ZVAL(nullval);
                    }
                    INIT_PZVAL(nullval);
                    ZVAL_NULL(nullval);
                }
                zend_hash_update(Z_ARRVAL(tmp), zero_terminated_key, key_len + 1, &nullval, sizeof(zval *), NULL);
                pefree(zero_terminated_key, persistent);
#else
                zend_string *key = zend_string_init(key_start, key_len, persistent);
                if (lowercase) {
                    zend_str_tolower(ZSTR_VAL(key), ZSTR_LEN(key));
                }
                zend_hash_add_empty_element(Z_ARRVAL(tmp), key);
                zend_string_release(key);
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

#if PHP_VERSION_ID < 70000
static void zai_config_persist_zval(zval *in);
static void zai_config_persist_zval_ptr(void *ptr) {
    zval **in = ptr, *out;
    ALLOC_PERMANENT_ZVAL(out);
    *out = **in;
    zai_config_persist_zval(out);
    efree(*in);
    *in = out;
}

static void zai_config_persist_zval(zval *in) {
    if (Z_TYPE_P(in) == IS_ARRAY) {
        HashTable *array = Z_ARRVAL_P(in);
        Z_ARRVAL_P(in) = malloc(sizeof(HashTable));
        zend_hash_init(Z_ARRVAL_P(in), 8, NULL, zai_config_dtor_ppzval, 1);
        zend_hash_copy(Z_ARRVAL_P(in), array, zai_config_persist_zval_ptr, NULL, sizeof(zval *));
        array->pDestructor = NULL;
        zend_hash_destroy(array);
        FREE_HASHTABLE(array);
    } else if (Z_TYPE_P(in) == IS_STRING) {
        char *str = Z_STRVAL_P(in);
        Z_STRVAL_P(in) = strndup(str, Z_STRLEN_P(in));
        efree(str);
    }
}
#else
static void zai_config_persist_zval(zval *in) {
    if (Z_TYPE_P(in) == IS_ARRAY) {
        zend_array *array = Z_ARR_P(in);
        ZVAL_NEW_PERSISTENT_ARR(in);
        zend_hash_init(Z_ARR_P(in), 8, NULL, zai_config_dtor_pzval, 1);
        Bucket *bucket;
        ZEND_HASH_FOREACH_BUCKET(array, bucket) {
            zai_config_persist_zval(&bucket->val);
            if (bucket->key) {
                zend_hash_str_add_new(Z_ARR_P(in), ZSTR_VAL(bucket->key), ZSTR_LEN(bucket->key), &bucket->val);
            } else {
                zend_hash_index_add_new(Z_ARR_P(in), bucket->h, &bucket->val);
            }
            ZVAL_NULL(&bucket->val);
        }
        ZEND_HASH_FOREACH_END();
        zend_hash_release(array);
    } else if (Z_TYPE_P(in) == IS_STRING) {
        zend_string *str = Z_STR_P(in);
        if (!(GC_FLAGS(str) & IS_STR_PERSISTENT)) {
            ZVAL_PSTRINGL(in, ZSTR_VAL(str), ZSTR_LEN(str));
            zend_string_release(str);
        }
    }
}
#endif

static bool zai_config_decode_json(zai_string_view value, zval *decoded_value, bool persistent) {
    ZAI_TSRMLS_FETCH();

    php_json_decode(decoded_value, (char *)value.ptr, (int)value.len, true, 20 ZAI_TSRMLS_CC);

    if (Z_TYPE_P(decoded_value) != IS_ARRAY) {
        zval_dtor(decoded_value);
        ZVAL_NULL(decoded_value);
        return false;
    }

    // as we do not want to parse the JSON ourselves, we have to ensure persistence ourselves by copying
    if (persistent) {
        zai_config_persist_zval(decoded_value);
    }

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
            return zai_config_decode_set(value, decoded_value, persistent, false);
        case ZAI_CONFIG_TYPE_SET_LOWERCASE:
            return zai_config_decode_set(value, decoded_value, persistent, true);
        case ZAI_CONFIG_TYPE_JSON:
            return zai_config_decode_json(value, decoded_value, persistent);
        case ZAI_CONFIG_TYPE_STRING:
            return zai_config_decode_string(value, decoded_value, persistent);
        default:
            assert(false && "Unknown zai_config_type");
    }
    return false;
}
