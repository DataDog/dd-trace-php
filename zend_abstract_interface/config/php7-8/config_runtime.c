#include <main/php.h>

#include "../config.h"

#ifdef ZTS
#define ZAI_TLS static __thread
#else
#define ZAI_TLS static
#endif

extern HashTable zai_config_name_map;

ZAI_TLS zval *runtime_config = NULL;  // dynamically allocated, otherwise TLS alignment limits may be exceeded

static void zai_config_runtime_config_update(void);

static inline void zai_config_runtime_dtor(zval *zv) {
    if (!Z_OPT_REFCOUNTED_P(zv)) {
        return;
    }

    if (Z_TYPE_P(zv) == IS_ARRAY) {
#ifdef IS_ARRAY_PERSISTENT
        if (!(GC_FLAGS(Z_COUNTED_P(zv)) & IS_ARRAY_PERSISTENT)) {
#else
        if (!(Z_ARRVAL_P(zv)->u.flags & HASH_FLAG_PERSISTENT)) {
#endif
            zval_ptr_dtor(zv);
        }
        return;
    }

    if (Z_TYPE_P(zv) == IS_STRING) {
        if (!(GC_FLAGS(Z_COUNTED_P(zv)) & IS_STR_PERSISTENT)) {
            zval_ptr_dtor(zv);
        }
    }
}

void zai_config_replace_runtime_config(zai_config_id id, zval *value) {
    zai_config_runtime_config_update();

    zval *rt_value = &runtime_config[id];
    zai_config_runtime_dtor(rt_value);

    ZVAL_COPY(rt_value, value);
}

static inline void zai_config_runtime_config_value(zai_config_memoized_entry *memoized, zval *runtime) {
    ZAI_ENV_BUFFER_INIT(buf, ZAI_ENV_MAX_BUFSIZ);

    for (int16_t name_index = 0; name_index < memoized->names_count; name_index++) {
        zai_string_view name = {.len = memoized->names[name_index].len, .ptr = memoized->names[name_index].ptr};
        zai_env_result result = zai_getenv_ex(name, buf, false);

        if (result == ZAI_ENV_SUCCESS) {
            /*
             * we unconditionally decode the value because we do not store the in-use encoded value
             * so we cannot compare the current environment value to the current configuration value
             * for the purposes of short circuiting decode
             */
            zai_string_view rte_value = {.len = strlen(buf.ptr), .ptr = buf.ptr};

            ZVAL_UNDEF(runtime);

            if (!zai_config_decode_value(rte_value, memoized->type, runtime, /* persistent */ false)) {
                break;
            }

            return;
        }
    }

    /* we must not write memory@memoized */
    ZVAL_COPY_VALUE(runtime, &memoized->decoded_value);
}

static void zai_config_runtime_config_update() {
    if (!runtime_config) {
        runtime_config = ecalloc(sizeof(zval), ZAI_CONFIG_ENTRIES_COUNT_MAX);
    }

    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        if (Z_OPT_REFCOUNTED(runtime_config[i])) {
            zai_config_runtime_dtor(&runtime_config[i]);
        }

        zai_config_runtime_config_value(&zai_config_memoized_entries[i], &runtime_config[i]);
    }
}

void zai_config_runtime_config_reset() { runtime_config = NULL; }

void zai_config_runtime_config_ctor(void) { zai_config_runtime_config_update(); }

void zai_config_runtime_config_dtor(void) {
    if (!runtime_config) {
        return;
    }

    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_config_runtime_dtor(&runtime_config[i]);
    }
    efree(runtime_config);

    zai_config_runtime_config_reset();
}

zval *zai_config_get_value(zai_config_id id) {
    if (id >= zai_config_memoized_entries_count) {
        assert(false && "Config ID is out of bounds");
        return &EG(error_zval);
    }

    if (!runtime_config) {
        return &zai_config_memoized_entries[id].decoded_value;
    }

    return &runtime_config[id];
}

void zai_config_register_config_id(zai_config_name *name, zai_config_id id) {
    zval tmp;
    ZVAL_LONG(&tmp, id);
    zend_hash_str_add(&zai_config_name_map, name->ptr, name->len, &tmp);
}

bool zai_config_get_id_by_name(zai_string_view name, zai_config_id *id) {
    if (!zai_config_name_map.nTableSize) return false;
    if (!zai_string_stuffed(name) || !id) return false;

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
