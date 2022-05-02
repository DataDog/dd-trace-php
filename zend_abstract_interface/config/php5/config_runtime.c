#include <main/php.h>

#include "../config.h"

#ifdef ZTS
#define ZAI_TLS static __thread
#else
#define ZAI_TLS static
#endif

extern HashTable zai_config_name_map;

ZAI_TLS zval **runtime_config;  // dynamically allocated, otherwise TLS alignment limits may be exceeded
ZAI_TLS bool runtime_config_initialized = false;

void zai_config_replace_runtime_config(zai_config_id id, zval *value) {
    zval **rt_value = &runtime_config[id];
    zval_ptr_dtor(rt_value);

    MAKE_STD_ZVAL(*rt_value);
    MAKE_COPY_ZVAL(&value, *rt_value);
}

static inline void zai_config_runtime_config_value(zai_config_memoized_entry *memoized, zval **updated) {
    ZAI_ENV_BUFFER_INIT(buf, ZAI_ENV_MAX_BUFSIZ);

    for (int16_t name_index = 0; name_index < memoized->names_count; name_index++) {
        zai_string_view name = {.len = memoized->names[name_index].len, .ptr = memoized->names[name_index].ptr};
        zai_env_result result = zai_getenv_ex(name, buf, false);

        if (result == ZAI_ENV_SUCCESS) {
            MAKE_STD_ZVAL(*updated);

            ZVAL_NULL(*updated);

            /*
             * we unconditionally decode the value because we do not store the in-use encoded value
             * so we cannot compare the current environment value to the current configuration value
             * for the purposes of short circuiting decode
             */
            zai_string_view rte_value = {.len = strlen(buf.ptr), .ptr = buf.ptr};

            if (!zai_config_decode_value(rte_value, memoized->type, *updated, /* persistent */ false)) {
                TSRMLS_FETCH();
                zval_dtor(*updated);
                FREE_ZVAL(*updated);
                break;
            }

            return;
        }
    }

    if (Z_TYPE(memoized->decoded_value) == IS_ARRAY) {
        // arrays will land in the GC root buffer and must actually be of type struct _zval_gc_info, which also
        // must not be shared across threads, hence forcing a copy of arrays
        ALLOC_ZVAL(*updated);
        INIT_PZVAL_COPY(*updated, &memoized->decoded_value);
        zval_copy_ctor(*updated);
    } else {
        *updated = &memoized->decoded_value;
        zval_add_ref(updated);
    }
}

void zai_config_runtime_config_ctor(void) {
    if (runtime_config_initialized == true) return;
    runtime_config = emalloc(sizeof(zval *) * ZAI_CONFIG_ENTRIES_COUNT_MAX);

    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_config_runtime_config_value(&zai_config_memoized_entries[i], &runtime_config[i]);
    }
    runtime_config_initialized = true;
}

void zai_config_runtime_config_dtor(void) {
    if (runtime_config_initialized != true) return;
    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zval_ptr_dtor(&runtime_config[i]);
    }

    efree(runtime_config);
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
    if (!zai_string_stuffed(name) || !id) return false;

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
