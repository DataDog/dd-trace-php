#include "../config.h"

#include <main/php.h>

#ifdef ZTS
#define ZAI_TLS static __thread
#else
#define ZAI_TLS static
#endif

extern HashTable zai_config_name_map;

ZAI_TLS zval runtime_config[ZAI_CONFIG_ENTRIES_COUNT_MAX];
ZAI_TLS bool runtime_config_initialized = false;

void zai_config_replace_runtime_config(zai_config_id id, zval *value) {
    zval *rt_value = &runtime_config[id];
    zval_ptr_dtor(rt_value);

    ZVAL_COPY(rt_value, value);
}

void zai_config_runtime_config_ctor(void) {
    if (runtime_config_initialized == true) return;
    for (uint8_t i = 0; i < memoized_entires_count; i++) {
        ZVAL_COPY(&runtime_config[i], &memoized_entires[i].decoded_value);
    }
    runtime_config_initialized = true;
}

void zai_config_runtime_config_dtor(void) {
    if (runtime_config_initialized != true) return;
    for (uint8_t i = 0; i < memoized_entires_count; i++) {
        zval_ptr_dtor(&runtime_config[i]);
    }
    runtime_config_initialized = false;
}

zval *zai_config_get_value(zai_config_id id) {
    if (id >= memoized_entires_count) {
        assert(false && "Config ID is out of bounds");
        return &EG(error_zval);
    }
    return &runtime_config[id];
}

void zai_config_register_config_id(zai_config_name *name, zai_config_id id) {
    zval tmp;
    ZVAL_LONG(&tmp, id);
    zend_hash_str_add(&zai_config_name_map, name->ptr, name->len, &tmp);
}

bool zai_config_get_id_by_name(zai_string_view name, zai_config_id *id) {
    if (!PG(modules_activated)) return false;
    if (!zai_config_name_map.nTableSize) return false;
    if (!name.ptr || !name.len || !id) return false;

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
