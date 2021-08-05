#include <main/php.h>

#include "../config.h"

#ifdef ZTS
#define ZAI_TLS static __thread
#else
#define ZAI_TLS static
#endif

extern HashTable zai_config_name_map;

ZAI_TLS zval *runtime_config[ZAI_CONFIG_ENTRIES_COUNT_MAX];
ZAI_TLS bool runtime_config_initialized = false;

void zai_config_replace_runtime_config(zai_config_id id, zval *value) {
    zval **rt_value = &runtime_config[id];
    zval_ptr_dtor(rt_value);

    MAKE_STD_ZVAL(*rt_value);
    MAKE_COPY_ZVAL(&value, *rt_value);
}

void zai_config_runtime_config_ctor(void) {
    if (runtime_config_initialized == true) return;
    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        runtime_config[i] = &zai_config_memoized_entries[i].decoded_value;
        zval_add_ref(&runtime_config[i]);
    }
    runtime_config_initialized = true;
}

void zai_config_runtime_config_dtor(void) {
    if (runtime_config_initialized != true) return;
    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zval_ptr_dtor(&runtime_config[i]);
    }
    runtime_config_initialized = false;
}

zval *zai_config_get_value(zai_config_id id) {
    if (id >= zai_config_memoized_entries_count) {
        assert(false && "Config ID is out of bounds");
        TSRMLS_FETCH();
        return &EG(uninitialized_zval);
    }
    if (runtime_config[id] == NULL) {
        assert(false && "runtime config is not yet initialized");
        TSRMLS_FETCH();
        return &EG(uninitialized_zval);
    }
    return runtime_config[id];
}

void zai_config_register_config_id(zai_config_name *name, zai_config_id id) {
    uintptr_t idp = id;
    zend_hash_add(&zai_config_name_map, name->ptr, name->len, (void **)&idp, sizeof(idp), NULL);
}

bool zai_config_get_id_by_name(zai_string_view name, zai_config_id *id) {
    if (!zai_config_name_map.nTableSize) return false;
    if (!name.ptr || !name.len || !id) return false;

    if (!zai_config_name_map.nTableSize) {
        assert(false && "INI name map not initialized");
        return false;
    }

    uintptr_t *zid;
    if (zend_hash_find(&zai_config_name_map, name.ptr, name.len, (void **)&zid) == SUCCESS) {
        *id = *zid;
        return true;
    }

    return false;
}
