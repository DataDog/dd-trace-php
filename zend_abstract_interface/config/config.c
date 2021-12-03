#include "./config.h"

#include <assert.h>
#include <main/php.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>

#include <json/json.h>

#if PHP_VERSION_ID < 70000
#define ZVAL_UNDEF(z)  \
    {                  \
        INIT_PZVAL(z); \
        ZVAL_NULL(z);  \
    }
#endif

HashTable zai_config_name_map = {0};

_Static_assert(ZAI_CONFIG_ENTRIES_COUNT_MAX < 256, "zai config entry count is overflowing uint8_t");
uint8_t zai_config_memoized_entries_count = 0;
zai_config_memoized_entry zai_config_memoized_entries[ZAI_CONFIG_ENTRIES_COUNT_MAX];

static bool zai_config_get_env_value(zai_string_view name, zai_env_buffer buf) {
    // TODO Handle other return codes
    // We want to explicitly allow pre-RINIT access to env vars here. So that callers can have an early view at config.
    // But in general allmost all configurations shall only be accessed after first RINIT. (the trivial getter will
    return zai_getenv_ex(name, buf, true) == ZAI_ENV_SUCCESS;
}

static void zai_config_find_and_set_value(zai_config_memoized_entry *memoized, zai_config_id id) {
    // TODO Use less buffer space
    // TODO Make a more generic zai_string_buffer
    ZAI_ENV_BUFFER_INIT(buf, ZAI_ENV_MAX_BUFSIZ);

    zval tmp;
    ZVAL_UNDEF(&tmp);

    zai_string_view value = {0};

    int16_t name_index = 0;
    for (; name_index < memoized->names_count; name_index++) {
        zai_string_view name = {.len = memoized->names[name_index].len, .ptr = memoized->names[name_index].ptr};
        if (zai_config_get_env_value(name, buf)) {
            zai_string_view env_value = {.len = strlen(buf.ptr), .ptr = buf.ptr};
            if (!zai_config_decode_value(env_value, memoized->type, &tmp, /* persistent */ true)) {
                // TODO Log decoding error
            } else {
                zai_config_dtor_pzval(&tmp);
                value = env_value;
            }
            break;
        }
    }

    int16_t ini_name_index = zai_config_initialize_ini_value(memoized->ini_entries, memoized->names_count, &value,
                                                             memoized->default_encoded_value, id);
    if (value.ptr != buf.ptr && ini_name_index >= 0) {
        name_index = ini_name_index;
    }

    if (value.ptr) {
        // TODO If name_index > 0, log deprecation notice
        zai_config_decode_value(value, memoized->type, &tmp, /* persistent */ true);
        assert(Z_TYPE(tmp) > IS_NULL);
        zai_config_dtor_pzval(&memoized->decoded_value);
        ZVAL_COPY_VALUE(&memoized->decoded_value, &tmp);
        memoized->name_index = name_index;
    }

    // Nothing to do; default value was already decoded at MINIT
}

static void zai_config_copy_name(zai_config_name *dest, zai_string_view src) {
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

    ZVAL_UNDEF(&memoized->decoded_value);
    if (!zai_config_decode_value(entry->default_encoded_value, memoized->type, &memoized->decoded_value,
                                 /* persistent */ true)) {
        assert(0 && "Error decoding default value");
    }
    memoized->name_index = -1;
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

void zai_config_minit(zai_config_entry entries[], size_t entries_count, zai_config_env_to_ini_name env_to_ini,
                      int module_number) {
    if (!entries || !entries_count) return;
    zai_json_setup_bindings();
    zai_config_entries_init(entries, entries_count);
    zai_config_ini_minit(env_to_ini, module_number);
}

static void zai_config_dtor_memoized_zvals(void) {
    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_config_dtor_pzval(&zai_config_memoized_entries[i].decoded_value);
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

void zai_config_first_time_rinit(void) {
    for (uint8_t i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[i];
        zai_config_find_and_set_value(memoized, i);
    }
}

void zai_config_rinit(void) {
    zai_config_runtime_config_ctor();
#if ZTS
    zai_config_ini_rinit();
#endif
}

void zai_config_rshutdown(void) { zai_config_runtime_config_dtor(); }

bool zai_config_system_ini_change(zval *old_value, zval *new_value) {
    (void)old_value;
    (void)new_value;
    return false;
}
