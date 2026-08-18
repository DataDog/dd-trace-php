#include "routing_cache.h"
#include "ddtrace.h"

static HashTable ddtrace_rcache;

static void ddtrace_routing_cache_dtor(zval *pz) {
    zend_string_release_ex((zend_string *)Z_PTR_P(pz), 1);
}

static void ddtrace_routing_cache_evict_oldest(void) {
    HashPosition pos;
    zend_string *key;
    zend_ulong num_idx;

    zend_hash_internal_pointer_reset_ex(&ddtrace_rcache, &pos);
    if (zend_hash_get_current_key_type_ex(&ddtrace_rcache, &pos) == HASH_KEY_IS_STRING) {
        zend_hash_get_current_key_ex(&ddtrace_rcache, &key, &num_idx, &pos);
        zend_hash_del(&ddtrace_rcache, key);
    }
}

void ddtrace_routing_cache_minit(void) {
    zend_hash_init(&ddtrace_rcache, DDTRACE_ROUTING_CACHE_CAPACITY, NULL, ddtrace_routing_cache_dtor, 1);
}

void ddtrace_routing_cache_mshutdown(void) {
    zend_hash_destroy(&ddtrace_rcache);
}

/* DDTrace\routing_cache_get(string $key): string|false */
PHP_FUNCTION(DDTrace_routing_cache_get) {
    zend_string *key;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(key)
    ZEND_PARSE_PARAMETERS_END();

    zend_string *value = zend_hash_find_ptr(&ddtrace_rcache, key);
    if (!value) {
        RETURN_FALSE;
    }
    RETURN_STRINGL(ZSTR_VAL(value), ZSTR_LEN(value));
}

/* DDTrace\routing_cache_set(string $key, string $value): void */
PHP_FUNCTION(DDTrace_routing_cache_set) {
    zend_string *key, *value;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(key)
        Z_PARAM_STR(value)
    ZEND_PARSE_PARAMETERS_END();

    if (zend_hash_num_elements(&ddtrace_rcache) >= DDTRACE_ROUTING_CACHE_CAPACITY
        && !zend_hash_find_ptr(&ddtrace_rcache, key)) {
        ddtrace_routing_cache_evict_oldest();
    }

    zend_string *persistent_value = zend_string_init(ZSTR_VAL(value), ZSTR_LEN(value), 1);
    zend_hash_str_update_ptr(&ddtrace_rcache, ZSTR_VAL(key), ZSTR_LEN(key), persistent_value);
}
