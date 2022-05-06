#include <main/php.h>

#include "../config.h"

#ifdef ZTS
#define ZAI_TLS static __thread
#else
#define ZAI_TLS static
#endif

extern HashTable zai_config_name_map;

ZAI_TLS zval **runtime_config = NULL;  // dynamically allocated, otherwise TLS alignment limits may be exceeded

static void zai_config_runtime_config_update(void);

static inline void zai_config_runtime_dtor(zval **zv) {
    if (Z_TYPE_PP(zv) != IS_ARRAY) {
        zval_ptr_dtor(zv);

        return;
    }

    if (!Z_ARRVAL_PP(zv)->persistent) {
        zval_ptr_dtor(zv);

        return;
    }

#ifdef ZTS
    TSRMLS_FETCH();
#endif

    if (Z_DELREF_PP(zv) == 0) {
        GC_REMOVE_ZVAL_FROM_BUFFER(*zv);
        FREE_ZVAL(*zv);
    } else {
        if (Z_REFCOUNT_PP(zv) == 1) {
            Z_UNSET_ISREF_PP(zv);
        }

        GC_ZVAL_CHECK_POSSIBLE_ROOT(*zv);
    }
}

void zai_config_replace_runtime_config(zai_config_id id, zval *value) {
    zai_config_runtime_config_update();

    zval **rt_value = &runtime_config[id];
    zai_config_runtime_dtor(rt_value);

    MAKE_STD_ZVAL(*rt_value);
    MAKE_COPY_ZVAL(&value, *rt_value);
}

static inline void zai_config_runtime_config_value(zai_config_memoized_entry *memoized, zval **runtime) {
    ZAI_ENV_BUFFER_INIT(buf, ZAI_ENV_MAX_BUFSIZ);

    for (int16_t name_index = 0; name_index < memoized->names_count; name_index++) {
        zai_string_view name = {.len = memoized->names[name_index].len, .ptr = memoized->names[name_index].ptr};
        zai_env_result result = zai_getenv_ex(name, buf, false);

        if (result == ZAI_ENV_SUCCESS) {
            MAKE_STD_ZVAL(*runtime);

            ZVAL_NULL(*runtime);

            /*
             * we unconditionally decode the value because we do not store the in-use encoded value
             * so we cannot compare the current environment value to the current configuration value
             * for the purposes of short circuiting decode
             */
            zai_string_view rte_value = {.len = strlen(buf.ptr), .ptr = buf.ptr};

            if (!zai_config_decode_value(rte_value, memoized->type, *runtime, /* persistent */ false)) {
                TSRMLS_FETCH();
                zval_dtor(*runtime);
                FREE_ZVAL(*runtime);
                break;
            }

            return;
        }
    }

    /* we must not write memory@memoized */
    ALLOC_ZVAL(*runtime);
    INIT_PZVAL_COPY(*runtime, &memoized->decoded_value);
    if (Z_TYPE_PP(runtime) != IS_ARRAY) {
        zval_copy_ctor(*runtime);
    }
}

void zai_config_runtime_config_update() {
    if (!runtime_config) {
        runtime_config = ecalloc(sizeof(zval *), ZAI_CONFIG_ENTRIES_COUNT_MAX);
    }

    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        if (runtime_config[i] != NULL) {
            zai_config_runtime_dtor(&runtime_config[i]);
        }

        zai_config_runtime_config_value(&zai_config_memoized_entries[i], &runtime_config[i]);
    }
}

void zai_config_runtime_config_reset() { runtime_config = NULL; }

void zai_config_runtime_config_ctor(void) { zai_config_runtime_config_update(); }

void zai_config_runtime_config_dtor(void) {
    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_config_runtime_dtor(&runtime_config[i]);
    }

    efree(runtime_config);

    zai_config_runtime_config_reset();
}

zval *zai_config_get_value(zai_config_id id) {
    if (id >= zai_config_memoized_entries_count) {
        assert(false && "Config ID is out of bounds");
        TSRMLS_FETCH();
        return &EG(uninitialized_zval);
    }
    if (!runtime_config) {
        return &zai_config_memoized_entries[id].decoded_value;
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
