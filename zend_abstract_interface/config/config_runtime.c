#include "../tsrmls_cache.h"
#include <main/php.h>

#include "config.h"

extern HashTable zai_config_name_map;

ZEND_TLS zval *runtime_config;  // dynamically allocated, otherwise TLS alignment limits may be exceeded
ZEND_TLS bool runtime_config_initialized = false;

void zai_config_replace_runtime_config(zai_config_id id, zval *value) {
    zval *rt_value = &runtime_config[id];
    zval_ptr_dtor(rt_value);

    ZVAL_COPY(rt_value, value);
}

bool zai_config_is_initialized(void) {
    return runtime_config_initialized;
}

void zai_config_runtime_config_ctor(void) {
    if (runtime_config_initialized == true) return;
    runtime_config = emalloc(sizeof(zval) * ZAI_CONFIG_ENTRIES_COUNT_MAX);

    for (uint16_t i = 0; i < zai_config_memoized_entries_count; i++) {
        ZVAL_COPY(&runtime_config[i], &zai_config_memoized_entries[i].decoded_value);
    }
    runtime_config_initialized = true;
}

void zai_config_runtime_config_dtor(void) {
    if (runtime_config_initialized != true) return;
    for (uint16_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zval_ptr_dtor(&runtime_config[i]);
    }
    efree(runtime_config);
    runtime_config_initialized = false;
}

zval *zai_config_get_value(zai_config_id id) {
    if (id >= zai_config_memoized_entries_count) {
        assert(false && "Config ID is out of bounds");
        return &EG(error_zval);
    }
    if (Z_ISUNDEF(runtime_config[id])) {
        assert(false && "runtime config is not yet initialized");
        return &EG(error_zval);
    }
    return &runtime_config[id];
}

void zai_config_register_config_id(zai_config_name *name, zai_config_id id) {
    zval tmp;
    ZVAL_LONG(&tmp, id);
    zend_hash_str_add(&zai_config_name_map, name->ptr, name->len, &tmp);
}

bool zai_config_get_id_by_name(zai_str name, zai_config_id *id) {
    if (!zai_config_name_map.nTableSize) return false;
    if (zai_str_is_empty(name) || !id) return false;

    assert(name.ptr && name.len && id);
    if (!zai_config_name_map.nTableSize) {
        assert(false && "INI name map not initialized");
        return false;
    }

    zval *zid = zend_hash_str_find(&zai_config_name_map, name.ptr, name.len);
    if (zid) {
        *id = Z_LVAL_P(zid);
        return true;
    }

    return false;
}
