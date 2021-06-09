#include "config.h"

#include <assert.h>
#include <main/php.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>

#ifdef ZTS
#define ZAI_TLS static __thread
#else
#define ZAI_TLS static
#endif

static zend_array config_name_map = {0};

uint8_t memoized_entires_count = 0;
zai_config_memoized_entry memoized_entires[ZAI_CONFIG_ENTRIES_COUNT_MAX];

ZAI_TLS zval runtime_config[ZAI_CONFIG_ENTRIES_COUNT_MAX];
ZAI_TLS bool runtime_config_initialized = false;

static bool zai_config_get_env_value(zai_string_view name, zai_env_buffer buf) {
    // TODO Handle other return codes
    return zai_getenv(name, buf) == ZAI_ENV_SUCCESS;
}

static void zai_config_dtor_pzval(zval *pval) {
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

static void zai_config_find_and_set_value(zai_config_memoized_entry *memoized) {
    // TODO Use less buffer space
    // TODO Make a more generic zai_string_buffer
    ZAI_ENV_BUFFER_INIT(buf, ZAI_ENV_MAX_BUFSIZ);

    bool found = false;
    int16_t name_index = 0;
    for (; name_index < memoized->names_count; name_index++) {
        zai_string_view name = {.len = memoized->names[name_index].len, .ptr = memoized->names[name_index].ptr};
        if (zai_config_get_env_value(name, buf) || zai_config_get_ini_value(name, buf)) {
            found = true;
            break;
        }
    }

    if (found) {
        zai_string_view value = {.len = strlen(buf.ptr), .ptr = buf.ptr};
        zval tmp;
        ZVAL_UNDEF(&tmp);
        // TODO If name_index > 0, log deprecation notice
        if (zai_config_decode_value(value, memoized->type, &tmp, /* persistent */ true)) {
            zai_config_dtor_pzval(&memoized->decoded_value);
            ZVAL_COPY_VALUE(&memoized->decoded_value, &tmp);
            memoized->name_index = name_index;
            return;
        }
        // TODO Log decoding errors
    }

    // Nothing to do; default value was already decoded at MINIT
}

static void zai_config_copy_name(zai_config_name *dest, zai_string_view src) {
    assert((src.len < ZAI_CONFIG_NAME_BUFSIZ) && "Name length greater than the buffer size");
    strncpy(dest->ptr, src.ptr, src.len);
    dest->len = src.len;
}

static zai_config_memoized_entry *zai_config_memoize_entry(zai_config_entry entry) {
    assert((entry.id < ZAI_CONFIG_ENTRIES_COUNT_MAX) && "Out of bounds config entry ID");
    assert((entry.aliases_count < ZAI_CONFIG_NAMES_COUNT_MAX) &&
           "Number of aliases + name are greater than ZAI_CONFIG_NAMES_COUNT_MAX");

    zai_config_memoized_entry *memoized = &memoized_entires[entry.id];

    zai_config_copy_name(&memoized->names[0], entry.name);
    for (uint8_t i = 0; i < entry.aliases_count; i++) {
        zai_config_copy_name(&memoized->names[i + 1], entry.aliases[i]);
    }
    memoized->names_count = entry.aliases_count + 1;

    memoized->type = entry.type;

    ZVAL_UNDEF(&memoized->decoded_value);
    bool ret = zai_config_decode_value(entry.default_encoded_value, memoized->type, &memoized->decoded_value,
                                       /* persistent */ true);
    assert(ret && "Error decoding default value");
    (void)ret;  // Used on debug builds only
    memoized->name_index = -1;

    return memoized;
}

static void zai_config_entries_init(zai_config_entry entries[], size_t entries_count) {
    assert((entries_count <= ZAI_CONFIG_ENTRIES_COUNT_MAX) &&
           "Number of config entries are greater than ZAI_CONFIG_ENTRIES_COUNT_MAX");

    memoized_entires_count = entries_count;

    zend_hash_init(&config_name_map, entries_count, NULL, NULL, /* persistent */ 1);

    for (size_t i = 0; i < entries_count; i++) {
        zai_config_memoized_entry *memoized = zai_config_memoize_entry(entries[i]);
        for (uint8_t n = 0; n < memoized->names_count; n++) {
            zval tmp;
            zai_config_name *name = &memoized->names[n];

            ZVAL_LONG(&tmp, (zend_long)i);
            zend_hash_str_add(&config_name_map, name->ptr, name->len, &tmp);
        }
    }
}

void zai_config_update_runtime_config(zai_config_id id, zval *value) {
    zval *rt_value = &runtime_config[id];
    zval_ptr_dtor(rt_value);

    ZVAL_COPY_VALUE(rt_value, value);
    zval_add_ref(rt_value);
}

void zai_config_minit(zai_config_entry entries[], size_t entries_count, zai_config_env_to_ini_name env_to_ini,
                      int module_number) {
    if (!entries || !entries_count) return;
    zai_config_entries_init(entries, entries_count);
    zai_config_ini_minit(env_to_ini, module_number);
}

static void zai_config_dtor_memoized_zvals(void) {
    for (uint8_t i = 0; i < memoized_entires_count; i++) {
        zai_config_dtor_pzval(&memoized_entires[i].decoded_value);
    }
}

void zai_config_mshutdown(void) {
    zai_config_dtor_memoized_zvals();
    if (GC_REFCOUNT(&config_name_map)) {
        zend_hash_destroy(&config_name_map);
    }
    zai_config_ini_mshutdown();
}

static void zai_config_runtime_config_ctor(void) {
    if (runtime_config_initialized == true) return;
    for (uint8_t i = 0; i < memoized_entires_count; i++) {
        ZVAL_COPY_VALUE(&runtime_config[i], &memoized_entires[i].decoded_value);
        zval_add_ref(&runtime_config[i]);
    }
    runtime_config_initialized = true;
}

static void zai_config_runtime_config_dtor(void) {
    if (runtime_config_initialized != true) return;
    for (uint8_t i = 0; i < memoized_entires_count; i++) {
        zval_ptr_dtor(&runtime_config[i]);
    }
    runtime_config_initialized = false;
}

void zai_config_first_time_rinit(void) {
    for (uint8_t i = 0; i < memoized_entires_count; i++) {
        zai_config_memoized_entry *memoized = &memoized_entires[i];
        zai_config_find_and_set_value(memoized);
    }
    zai_config_runtime_config_ctor();
}

void zai_config_rinit(void) { zai_config_runtime_config_ctor(); }

void zai_config_rshutdown(void) { zai_config_runtime_config_dtor(); }

// ---

zval *zai_config_get_value(zai_config_id id) {
    if (id >= memoized_entires_count) {
        assert(false && "Config ID is out of bounds");
        return &EG(error_zval);
    }
    return &runtime_config[id];
}

static bool zai_config_zval_is_map(zval *value) {
    if (Z_TYPE_P(value) != IS_ARRAY) return false;
    // TODO Validate map of strings
    return true;
}

static bool zai_config_zval_is_expected_type(zval *value, zai_config_type type) {
    switch (type) {
        case ZAI_CONFIG_TYPE_BOOL:
            return Z_TYPE_P(value) == IS_TRUE || Z_TYPE_P(value) == IS_FALSE;
        case ZAI_CONFIG_TYPE_DOUBLE:
            return Z_TYPE_P(value) == IS_DOUBLE;
        case ZAI_CONFIG_TYPE_INT:
            return Z_TYPE_P(value) == IS_LONG;
        case ZAI_CONFIG_TYPE_MAP:
            return zai_config_zval_is_map(value);
        case ZAI_CONFIG_TYPE_STRING:
            return Z_TYPE_P(value) == IS_STRING;
    }
    assert(false && "Unknown zai_config_type");
    return false;
}

zai_config_result zai_config_set_value(zai_config_id id, zval *value) {
    if (!PG(modules_activated)) return ZAI_CONFIG_ERROR_NOT_READY;
    if (id >= memoized_entires_count || !value) return ZAI_CONFIG_ERROR;

    zai_config_memoized_entry *memoized = &memoized_entires[id];

    if (!zai_config_zval_is_expected_type(value, memoized->type)) {
        if (Z_TYPE_P(value) != IS_STRING) return ZAI_CONFIG_ERROR_INVALID_TYPE;

        zval tmp;
        ZVAL_UNDEF(&tmp);
        zai_string_view value_view = {.len = Z_STRLEN_P(value), .ptr = Z_STRVAL_P(value)};

        if (zai_config_decode_value(value_view, memoized->type, &tmp, /* persistent */ false)) {
            zai_config_update_runtime_config(id, &tmp);
            zval_ptr_dtor(&tmp);
            return ZAI_CONFIG_SUCCESS;
        }

        return ZAI_CONFIG_ERROR_DECODING;
    }

    zai_config_update_runtime_config(id, value);

    return ZAI_CONFIG_SUCCESS;
}

bool zai_config_get_id_by_name(zai_string_view name, zai_config_id *id) {
    if (!PG(modules_activated)) return false;
    if (!GC_REFCOUNT(&config_name_map)) return false;
    if (!name.ptr || !name.len || !id) return false;

    zval *zid = zend_hash_str_find(&config_name_map, name.ptr, name.len);
    if (zid && Z_TYPE_P(zid) == IS_LONG) {
        *id = Z_LVAL_P(zid);
        return true;
    }

    return false;
}
