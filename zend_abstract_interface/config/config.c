#include "./config.h"

#include <assert.h>
#include <json/json.h>
#include <main/php.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>

HashTable zai_config_name_map = {0};

#ifndef _WIN32
_Static_assert(ZAI_CONFIG_ENTRIES_COUNT_MAX < 256, "zai config entry count is overflowing uint8_t");
#endif
uint8_t zai_config_memoized_entries_count = 0;
zai_config_memoized_entry zai_config_memoized_entries[ZAI_CONFIG_ENTRIES_COUNT_MAX];

static bool zai_config_get_env_value(zai_str name, zai_env_buffer buf) {
    // TODO Handle other return codes
    // We want to explicitly allow pre-RINIT access to env vars here. So that callers can have an early view at config.
    // But in general allmost all configurations shall only be accessed after first RINIT. (the trivial getter will
    return zai_getenv_ex(name, buf, true) == ZAI_ENV_SUCCESS;
}

static inline void zai_config_process_env(zai_config_memoized_entry *memoized, zai_env_buffer buf, zai_option_str *value) {
    zval tmp;
    ZVAL_UNDEF(&tmp);
    zai_str env_value = ZAI_STR_FROM_CSTR(buf.ptr);
    if (!zai_config_decode_value(env_value, memoized->type, memoized->parser, &tmp, /* persistent */ true)) {
        // TODO Log decoding error
    } else {
        zai_json_dtor_pzval(&tmp);
        *value = zai_option_str_from_str(env_value);
    }
}

static void zai_config_find_and_set_value(zai_config_memoized_entry *memoized, zai_config_id id) {
    // TODO Use less buffer space
    // TODO Make a more generic zai_string_buffer
    ZAI_ENV_BUFFER_INIT(buf, ZAI_ENV_MAX_BUFSIZ);

    zai_option_str value = ZAI_OPTION_STR_NONE;

    int16_t name_index = 0;
    for (; name_index < memoized->names_count; name_index++) {
        zai_str name = {.len = memoized->names[name_index].len, .ptr = memoized->names[name_index].ptr};
        if (zai_config_get_env_value(name, buf)) {
            zai_config_process_env(memoized, buf, &value);
            break;
        }
    }
    if (!value.len && memoized->env_config_fallback && memoized->env_config_fallback(buf, true)) {
        zai_config_process_env(memoized, buf, &value);
        name_index = 0;
    }

    int16_t ini_name_index = zai_config_initialize_ini_value(memoized->ini_entries, memoized->names_count, &value,
                                                             memoized->default_encoded_value, id);

    zai_str value_view;
    if (zai_option_str_get(value, &value_view)) {
        if (value_view.ptr != buf.ptr && ini_name_index >= 0) {
            name_index = ini_name_index;
        }
        // TODO If name_index > 0, log deprecation notice

        zval tmp;
        ZVAL_UNDEF(&tmp);

        zai_config_decode_value(value_view, memoized->type, memoized->parser, &tmp, /* persistent */ true);
        assert(Z_TYPE(tmp) > IS_NULL);
        zai_json_dtor_pzval(&memoized->decoded_value);
        ZVAL_COPY_VALUE(&memoized->decoded_value, &tmp);
        memoized->name_index = name_index;
    }

    // Nothing to do; default value was already decoded at MINIT
}

static void zai_config_copy_name(zai_config_name *dest, zai_str src) {
    assert((src.len < ZAI_CONFIG_NAME_BUFSIZ) && "Name length greater than the buffer size");
    strncpy(dest->ptr, src.ptr, src.len);
    dest->len = src.len;
}

static zai_config_memoized_entry *zai_config_memoize_entry(zai_config_entry *entry) {
    assert((entry->id < ZAI_CONFIG_ENTRIES_COUNT_MAX) && "Out of bounds config entry ID");
    assert((entry->aliases_count < ZAI_CONFIG_NAMES_COUNT_MAX) &&
           "Number of aliases + name are greater than ZAI_CONFIG_NAMES_COUNT_MAX");

    zai_config_memoized_entry *memoized = &zai_config_memoized_entries[entry->id];

    zai_config_copy_name(&memoized->names[0], entry->name);
    for (uint8_t i = 0; i < entry->aliases_count; i++) {
        zai_config_copy_name(&memoized->names[i + 1], entry->aliases[i]);
    }
    memoized->names_count = entry->aliases_count + 1;

    memoized->type = entry->type;
    memoized->default_encoded_value = entry->default_encoded_value;
    memoized->parser = entry->parser;

    ZVAL_UNDEF(&memoized->decoded_value);
    if (!zai_config_decode_value(entry->default_encoded_value, memoized->type, memoized->parser, &memoized->decoded_value, /* persistent */ true)) {
        assert(0 && "Error decoding default value");
    }
    memoized->name_index = -1;
    memoized->original_on_modify = NULL;
    memoized->env_config_fallback = entry->env_config_fallback;
    memoized->ini_change = entry->ini_change;

    return memoized;
}

static void zai_config_entries_init(zai_config_entry entries[], zai_config_id entries_count) {
    assert((entries_count <= ZAI_CONFIG_ENTRIES_COUNT_MAX) &&
           "Number of config entries are greater than ZAI_CONFIG_ENTRIES_COUNT_MAX");

    zai_config_memoized_entries_count = entries_count;

    zend_hash_init(&zai_config_name_map, entries_count * 2, NULL, NULL, /* persistent */ 1);

    for (zai_config_id i = 0; i < entries_count; i++) {
        zai_config_memoized_entry *memoized = zai_config_memoize_entry(&entries[i]);
        for (uint8_t n = 0; n < memoized->names_count; n++) {
            zai_config_register_config_id(&memoized->names[n], i);
        }
    }
}

bool zai_config_minit(zai_config_entry entries[], size_t entries_count, zai_config_env_to_ini_name env_to_ini,
                      int module_number) {
    if (!entries || !entries_count) return false;
    if (!zai_json_setup_bindings()) return false;
    zai_config_entries_init(entries, entries_count);
    zai_config_ini_minit(env_to_ini, module_number);
    return true;
}

static void zai_config_dtor_memoized_zvals(void) {
    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_json_dtor_pzval(&zai_config_memoized_entries[i].decoded_value);
    }
}

void zai_config_mshutdown(void) {
    zai_config_dtor_memoized_zvals();
    if (zai_config_name_map.nTableSize) {
        zend_hash_destroy(&zai_config_name_map);
    }
    zai_config_ini_mshutdown();
}

void zai_config_runtime_config_ctor(void);
void zai_config_runtime_config_dtor(void);

#if PHP_VERSION_ID < 70300
#define GC_ADD_FLAGS(c, flag) GC_FLAGS(c) |= flag
#define GC_ADDREF(p) ++GC_REFCOUNT(p)
#endif

static void zai_config_intern_zval(zval *pzval) {
    if (Z_TYPE_P(pzval) == IS_STRING) {
#if PHP_VERSION_ID >= 70400
        ZVAL_INTERNED_STR(pzval, zend_new_interned_string(Z_STR_P(pzval)));
#else
        GC_ADD_FLAGS(Z_STR_P(pzval), IS_STR_INTERNED);
        Z_TYPE_INFO_P(pzval) = IS_INTERNED_STRING_EX;
#endif
    }
    if (Z_TYPE_P(pzval) == IS_ARRAY) {
        GC_ADDREF(Z_ARR_P(pzval));
        GC_ADD_FLAGS(Z_ARR_P(pzval), IS_ARRAY_IMMUTABLE);
#if PHP_VERSION_ID < 70200
        Z_TYPE_FLAGS_P(pzval) = IS_TYPE_IMMUTABLE;
#elif PHP_VERSION_ID < 70300
        Z_TYPE_FLAGS_P(pzval) = IS_TYPE_COPYABLE;
#else
        Z_TYPE_FLAGS_P(pzval) = 0;
#endif

#if PHP_VERSION_ID >= 80200
        if (HT_IS_PACKED(Z_ARR_P(pzval))) {
            zval *zv;
            ZEND_HASH_FOREACH_VAL(Z_ARR_P(pzval), zv) {
                zai_config_intern_zval(zv);
            } ZEND_HASH_FOREACH_END();
        } else
#endif
        {
        Bucket *bucket;
            ZEND_HASH_FOREACH_BUCKET(Z_ARR_P(pzval), bucket) {
                if (bucket->key) {
#if PHP_VERSION_ID >= 70400
                    bucket->key = zend_new_interned_string(bucket->key);
#else
                    GC_ADD_FLAGS(bucket->key, IS_STR_INTERNED);
#endif
                }
                zai_config_intern_zval(&bucket->val);
            } ZEND_HASH_FOREACH_END();
        }
    }
}

void zai_config_first_time_rinit(bool in_request) {
#if PHP_VERSION_ID >= 70400
    if (in_request) {
        zend_interned_strings_switch_storage(0);
    }
#endif

    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[i];
        zai_config_find_and_set_value(memoized, i);
        if (in_request) {
            zai_config_intern_zval(&memoized->decoded_value);
        }
    }

#if PHP_VERSION_ID >= 70400
    if (in_request) {
        zend_interned_strings_switch_storage(1);
    }
#endif
}

void zai_config_rinit(void) {
    zai_config_runtime_config_ctor();
    zai_config_ini_rinit();
}

void zai_config_rshutdown(void) { zai_config_runtime_config_dtor(); }

bool zai_config_system_ini_change(zval *old_value, zval *new_value, zend_string *new_str) {
    (void)old_value;
    (void)new_value;
    (void)new_str;
    return false;
}
