#include <php.h>
#include <stdbool.h>

static inline void dd_add_assoc_string(HashTable *ht, const char *name, size_t name_len, const char *str) {
    zval value;
    size_t str_len = str ? strlen(str) : 0;
    if (str_len > 0) {
        ZVAL_STRINGL(&value, str, str_len);
    } else {
        ZVAL_NULL(&value);
    }
    zend_hash_str_update(ht, name, name_len, &value);
}

static inline void dd_add_assoc_string_free(HashTable *ht, const char *name, size_t name_len, char *str) {
    dd_add_assoc_string(ht, name, name_len, (const char *)str);
    free(str);
}

static inline void dd_add_assoc_array(HashTable *ht, const char *name, size_t name_len, zend_array *array) {
    zval value;
    ZVAL_ARR(&value, array);
    zend_hash_str_update(ht, name, name_len, &value);
}

static inline void dd_add_assoc_zstring(HashTable *ht, const char *name, size_t name_len, zend_string *str) {
    zval value;
    if (ZSTR_LEN(str) == 0) {
        zend_string_release(str);
        ZVAL_NULL(&value);
    } else {
        ZVAL_STR(&value, str);
    }
    zend_hash_str_update(ht, name, name_len, &value);
}

static inline void dd_add_assoc_bool(HashTable *ht, const char *name, size_t name_len, bool v) {
    zval value;
    ZVAL_BOOL(&value, v);
    zend_hash_str_update(ht, name, name_len, &value);
}

static inline void dd_add_assoc_double(HashTable *ht, const char *name, size_t name_len, double num) {
    zval value;
    ZVAL_DOUBLE(&value, num);
    zend_hash_str_update(ht, name, name_len, &value);
}

static inline char *dd_get_ini(const char *name, size_t name_len) { return zend_ini_string((char *)name, name_len, 0); }

static inline bool dd_ini_is_set(const char *name, size_t name_len) {
    const char *ini = dd_get_ini(name, name_len);
    return ini && (strcmp(ini, "") != 0);
}

// Modified version of zend_ini_parse_bool()
// @see https://github.com/php/php-src/blob/28b4761/Zend/zend_ini.c#L493-L502
static inline bool dd_parse_bool(const char *name, size_t name_len) {
    const char *ini = dd_get_ini(name, name_len);
    size_t ini_len = strlen(ini);
    if ((ini_len == 4 && strcasecmp(ini, "true") == 0) || (ini_len == 3 && strcasecmp(ini, "yes") == 0) ||
        (ini_len == 2 && strcasecmp(ini, "on") == 0)) {
        return 1;
    } else {
        return atoi(ini) != 0;
    }
}

static inline zend_array *dd_array_copy(zend_array *array) {
    if (!(GC_FLAGS(array) & IS_ARRAY_IMMUTABLE)) {
        GC_ADDREF(array);
        return array;
    }

    // If it's not duplicated, it may crash later e.g. in json encoding.
    return zend_array_dup(array);
}

static inline zend_string *dd_implode_keys(zend_array *array) {
    smart_str imploded = {0};
    zend_string *key;
    ZEND_HASH_FOREACH_STR_KEY(array, key) {
        if (imploded.a != 0) {
            smart_str_appendc(&imploded, ',');
        }
        smart_str_append(&imploded, key);
    }
    ZEND_HASH_FOREACH_END();
    smart_str_0(&imploded);
    return imploded.s ? imploded.s : ZSTR_EMPTY_ALLOC();
}
