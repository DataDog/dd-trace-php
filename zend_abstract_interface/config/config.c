#include "./config.h"

#include <assert.h>
#include <main/php.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>

#if PHP_VERSION_ID < 70000
#undef zval_internal_ptr_dtor
#define zval_internal_ptr_dtor zval_internal_dtor
#define ZVAL_UNDEF(z) { INIT_PZVAL(z); ZVAL_NULL(z); }
#endif

HashTable zai_config_name_map = {0};

uint8_t memoized_entires_count = 0;
zai_config_memoized_entry memoized_entires[ZAI_CONFIG_ENTRIES_COUNT_MAX];

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
    for (; !found && name_index < memoized->names_count; name_index++) {
        zai_string_view name = {.len = memoized->names[name_index].len, .ptr = memoized->names[name_index].ptr};
        if (zai_config_get_env_value(name, buf)) {
            found = true;
        }
    }

    zai_string_view value = {.len = strlen(buf.ptr), .ptr = found ? buf.ptr : NULL};
    int16_t ini_name_index = zai_config_initialize_ini_value(memoized->names, memoized->names_count, &value, memoized->ini_entries);
    if (!found && ini_name_index >= 0) {
        name_index = ini_name_index;
    }

    if (value.ptr) {
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

static zai_config_memoized_entry *zai_config_memoize_entry(zai_config_entry *entry) {
    assert((entry->id < ZAI_CONFIG_ENTRIES_COUNT_MAX) && "Out of bounds config entry ID");
    assert((entry->aliases_count < ZAI_CONFIG_NAMES_COUNT_MAX) &&
           "Number of aliases + name are greater than ZAI_CONFIG_NAMES_COUNT_MAX");

    zai_config_memoized_entry *memoized = &memoized_entires[entry->id];

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

    memoized_entires_count = entries_count;

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
    if (zai_config_name_map.nTableSize) {
        zend_hash_destroy(&zai_config_name_map);
    }
    zai_config_ini_mshutdown();
}

void zai_config_runtime_config_ctor(void);
void zai_config_runtime_config_dtor(void);

void zai_config_first_time_rinit(void) {
    for (uint8_t i = 0; i < memoized_entires_count; i++) {
        zai_config_memoized_entry *memoized = &memoized_entires[i];
        zai_config_find_and_set_value(memoized);
    }
    zai_config_runtime_config_ctor();
}

void zai_config_rinit(void) { zai_config_runtime_config_ctor(); }

void zai_config_rshutdown(void) { zai_config_runtime_config_dtor(); }
