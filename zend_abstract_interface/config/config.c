#include "./config.h"

#include <SAPI.h>
#include <assert.h>
#include <json/json.h>
#include <main/php.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>

HashTable zai_config_name_map = {0};

uint16_t zai_config_memoized_entries_count = 0;
zai_config_memoized_entry zai_config_memoized_entries[ZAI_CONFIG_ENTRIES_COUNT_MAX];

/*
 * File-local owning zai_option_str cache:
 *   - None (ptr == NULL): env var was unset.
 *   - Some with len == 0: env var was explicitly set to empty.
 *   - Some with len > 0: env var has a non-empty value.
 *
 * Each Some entry owns pemalloc(..., 1) memory and is freed in
 * zai_config_clear_cached_env_values().
 */
static zai_option_str zai_config_cached_env_values[ZAI_CONFIG_ENTRIES_COUNT_MAX][ZAI_CONFIG_NAMES_COUNT_MAX];

zai_option_str zai_config_sys_getenv_cached(zai_config_id id, uint8_t name_index) {
    ZEND_ASSERT(id < zai_config_memoized_entries_count);
    ZEND_ASSERT(name_index < zai_config_memoized_entries[id].names_count);
    return zai_config_cached_env_values[id][name_index];
}

static void zai_config_cache_env_values(void) {
    for (zai_config_id i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[i];
        for (uint8_t n = 0; n < memoized->names_count; n++) {
            zai_str name = {.len = memoized->names[n].len, .ptr = memoized->names[n].ptr};

            zai_option_str val = zai_sys_getenv(name);
            if (zai_option_str_is_none(val)) {
                continue;
            }

            size_t len = val.len;
            // +1 to hold the null terminator.
            char *dst = pemalloc(len + 1, 1);
            // +1 to include the null terminator in the copy.
            memcpy(dst, val.ptr, len + 1);
            zai_config_cached_env_values[i][n] = zai_option_str_from_raw_parts(dst, len);
        }
    }
}

static void zai_config_clear_cached_env_values(void) {
    for (zai_config_id i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[i];
        for (uint8_t n = 0; n < memoized->names_count; n++) {
            zai_option_str *cached = &zai_config_cached_env_values[i][n];
            if (zai_option_str_is_some(*cached)) {
                pefree((char *)cached->ptr, 1);
            }
            *cached = ZAI_OPTION_STR_NONE;
        }
    }
}

static zai_option_str zai_config_getenv(zai_str name, zai_config_id id, uint8_t name_index, bool in_request) {
    zai_option_str val = ZAI_OPTION_STR_NONE;
    if (in_request) {
        val = zai_sapi_getenv(name);
        if (zai_option_str_is_some(val)) {
            return val;
        }
    }

    return zai_config_sys_getenv_cached(id, name_index);
}

/**
 * Decodes the environment variable value and returns the decoded value, or
 * None if the decoding fails.
 */
static inline zai_option_str zai_config_process_env(zai_config_memoized_entry *memoized, zai_str val) {
    zval tmp;
    ZVAL_UNDEF(&tmp);
    zai_option_str value = ZAI_OPTION_STR_NONE;
    if (!zai_config_decode_value(val, memoized->type, memoized->parser, &tmp, /* persistent */ true)) {
        // TODO Log decoding error
    } else {
        zai_json_dtor_pzval(&tmp);
        value = zai_option_str_from_str(val);
    }
    return value;
}

static void zai_config_find_and_set_value(zai_config_memoized_entry *memoized, zai_config_id id, bool in_request) {
    zai_option_str value = ZAI_OPTION_STR_NONE;

    int16_t name_index = 0;
    for (; name_index < memoized->names_count; name_index++) {
        zai_str name = {.len = memoized->names[name_index].len, .ptr = memoized->names[name_index].ptr};
        zai_config_stable_file_entry *entry = zai_config_stable_file_get_value(name);
        if (entry && entry->source == DDOG_LIBRARY_CONFIG_SOURCE_FLEET_STABLE_CONFIG) {
            zai_str val = zai_str_from_zstr(entry->value);
            value = zai_config_process_env(memoized, val);
            name_index = ZAI_CONFIG_ORIGIN_FLEET_STABLE;
            memoized->config_id = (zai_str) ZAI_STR_FROM_ZSTR(entry->config_id);
            break;
        };
        {
            zai_str val;
            zai_option_str maybe_val = zai_config_getenv(name, id, (uint8_t)name_index, in_request);
            if (zai_option_str_get(maybe_val, &val)) {
                value = zai_config_process_env(memoized, val);
                break;
            }
        }
        if (entry && entry->source == DDOG_LIBRARY_CONFIG_SOURCE_LOCAL_STABLE_CONFIG) {
            zai_str val = zai_str_from_zstr(entry->value);
            value = zai_config_process_env(memoized, val);
            name_index = ZAI_CONFIG_ORIGIN_LOCAL_STABLE;
            memoized->config_id = (zai_str) ZAI_STR_FROM_ZSTR(entry->config_id);
            break;
        }
    }

    ZAI_ENV_BUFFER_INIT(buf, ZAI_ENV_MAX_BUFSIZ);
    if (zai_option_str_is_none(value) && memoized->env_config_fallback && memoized->env_config_fallback(&buf, true)) {
        value = zai_config_process_env(memoized, (zai_str){buf.ptr, buf.len});
        name_index = ZAI_CONFIG_ORIGIN_MODIFIED;
    }

    int16_t ini_name_index = zai_config_initialize_ini_value(memoized->ini_entries, memoized->names_count, &value,
                                                             memoized->default_encoded_value, id);

    zai_str value_view;
    if (zai_option_str_get(value, &value_view)) {
        if (ini_name_index >= 0) {
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
    memoized->displayer = entry->displayer;

    ZVAL_UNDEF(&memoized->decoded_value);
    if (!zai_config_decode_value(entry->default_encoded_value, memoized->type, memoized->parser, &memoized->decoded_value, /* persistent */ true)) {
        assert(0 && "Error decoding default value");
    }
    memoized->name_index = ZAI_CONFIG_ORIGIN_DEFAULT;
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

#if PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400
zend_new_interned_string_func_t zai_persistent_new_interned_string;
#endif

bool zai_config_minit(zai_config_entry entries[], size_t entries_count, zai_config_env_to_ini_name env_to_ini,
                      int module_number) {
    if (!entries || !entries_count) return false;
    if (!zai_json_setup_bindings()) return false;
    zai_config_entries_init(entries, entries_count);
    zai_config_cache_env_values();
    zai_config_ini_minit(env_to_ini, module_number);
    zai_config_stable_file_minit();
#if PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400
    zai_persistent_new_interned_string = zend_new_interned_string;
#endif
    return true;
}

static void zai_config_dtor_memoized_zvals(void) {
    for (uint16_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_json_dtor_pzval(&zai_config_memoized_entries[i].decoded_value);
    }
}

void zai_config_mshutdown(void) {
    zai_config_dtor_memoized_zvals();
    zai_config_clear_cached_env_values();
    if (zai_config_name_map.nTableSize) {
        zend_hash_destroy(&zai_config_name_map);
    }
    zai_config_ini_mshutdown();
    zai_config_stable_file_mshutdown();
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
#elif PHP_VERSION_ID >= 70300
        ZVAL_INTERNED_STR(pzval, zai_persistent_new_interned_string(Z_STR_P(pzval)));
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
#elif PHP_VERSION_ID >= 70300
                    bucket->key = zai_persistent_new_interned_string(bucket->key);
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
    // On PHP 7.3 zend_interned_strings_switch_storage has undesired side effects (it calls interned_string_copy_storage); hence we collect zend_new_interned_string_permanent via zai_persistent_new_interned_string at minit.
#if PHP_VERSION_ID >= 70400
    if (in_request) {
        zend_interned_strings_switch_storage(0);
    }
#else
    (void)in_request;
#endif

    // Refresh process env snapshot for SAPIs like FPM that materialize pool
    // env values just before the first request. Skip on CLI since we know it
    // doesn't need it and we can avoid the extra work.
    if (in_request && strcmp(sapi_module.name, "cli") != 0) {
        zai_config_clear_cached_env_values();
        zai_config_cache_env_values();
    }

    for (uint16_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[i];
        zai_config_find_and_set_value(memoized, i, in_request);
#if PHP_VERSION_ID >= 70300
        zai_config_intern_zval(&memoized->decoded_value);
#else
        if (in_request) {
            zai_config_intern_zval(&memoized->decoded_value);
        }
#endif
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
