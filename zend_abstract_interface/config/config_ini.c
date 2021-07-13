#include "config_ini.h"

#include <assert.h>
#include <main/php.h>
#include <stdbool.h>
#include <string.h>

#include "config.h"

#define UNUSED(x) (void)(x)

static void (*env_to_ini_name)(zai_string_view env_name, zai_config_ini_name *ini_name);

static zend_array config_ini_name_map = {0};

static bool zai_config_generate_ini_name(zai_string_view name, zai_config_ini_name *ini_name) {
    ini_name->len = 0;
    *ini_name->ptr = '\0';

    env_to_ini_name(name, ini_name);

    return ini_name->len && *ini_name->ptr;
}

bool zai_config_get_ini_value(zai_string_view name, zai_env_buffer buf) {
    if (!env_to_ini_name) return false;

    zai_config_ini_name ini_name;
    if (!zai_config_generate_ini_name(name, &ini_name)) return false;

    zend_bool exists;
    char *raw_value = zend_ini_string_ex(ini_name.ptr, ini_name.len, /* orig */ 0, &exists);
    if (!exists || !raw_value || !*raw_value) return false;

    size_t len = strlen(raw_value);
    // TODO Log this message
    assert((len < buf.len) && "INI value is greater than the buffer size");
    if (len >= buf.len) return false;

    strncpy(buf.ptr, raw_value, len);
    buf.len = len;

    return true;
}

static bool zai_config_get_id_by_ini_name(zai_string_view name, zai_config_id *id) {
    assert(name.ptr && name.len && id);
    if (!GC_REFCOUNT(&config_ini_name_map)) {
        assert(false && "INI name map not initialized");
        return false;
    }

    zval *zid = zend_hash_str_find(&config_ini_name_map, name.ptr, name.len);
    if (zid && Z_TYPE_P(zid) == IS_LONG) {
        *id = Z_LVAL_P(zid);
        return true;
    }

    return false;
}

static ZEND_INI_MH(ZaiConfigOnUpdateIni) {
    UNUSED(mh_arg1);
    UNUSED(mh_arg2);
    UNUSED(mh_arg3);

    /* Ignore calls that happen before runtime (e.g. the default INI values on MINIT). System values are obtained on
     * first-time RINIT. */
    if (stage != PHP_INI_STAGE_RUNTIME) return SUCCESS;

    zai_config_id id;
    zai_string_view name = {.len = ZSTR_LEN(entry->name), .ptr = ZSTR_VAL(entry->name)};

    if (!zai_config_get_id_by_ini_name(name, &id)) {
        // TODO Log cannot find ID
        return FAILURE;
    }

    zval tmp;
    ZVAL_UNDEF(&tmp);
    zai_string_view value_view = {.len = ZSTR_LEN(new_value), .ptr = ZSTR_VAL(new_value)};

    if (zai_config_decode_value(value_view, memoized_entires[id].type, &tmp, /* persistent */ false)) {
        zai_config_update_runtime_config(id, &tmp);
        zval_ptr_dtor(&tmp);
        return SUCCESS;
    }

    // TODO Log decoding error

    return FAILURE;
}

static bool zai_config_add_ini_entry(zai_string_view name, zai_config_ini_name *ini_name, int module_number) {
    if (!zai_config_generate_ini_name(name, ini_name)) {
        assert(false && "Invalid INI name conversion");
        return false;
    }

    /* ZEND_INI_END() adds a null terminating entry */
    zend_ini_entry_def entry_defs[1 + /* terminator entry */ 1] = {{0}, {0}};
    zend_ini_entry_def *entry = &entry_defs[0];

    entry->name = ini_name->ptr;
    entry->name_length = ini_name->len;
    entry->value = "";
    entry->value_length = 0;
    entry->on_modify = ZaiConfigOnUpdateIni;
    entry->modifiable = PHP_INI_ALL;

    return zend_register_ini_entries(entry_defs, module_number) == SUCCESS;
}

void zai_config_ini_minit(zai_config_env_to_ini_name env_to_ini, int module_number) {
    env_to_ini_name = env_to_ini;

    if (!env_to_ini_name || !memoized_entires_count) return;

    zend_hash_init(&config_ini_name_map, memoized_entires_count, NULL, NULL, /* persistent */ 1);

    zai_config_ini_name ini_name;

    for (uint8_t i = 0; i < memoized_entires_count; i++) {
        zai_config_memoized_entry *memoized = &memoized_entires[i];
        for (uint8_t n = 0; n < memoized->names_count; n++) {
            zai_string_view name = {.len = memoized->names[n].len, .ptr = memoized->names[n].ptr};
            if (zai_config_add_ini_entry(name, &ini_name, module_number)) {
                zval tmp;
                ZVAL_LONG(&tmp, (zend_long)i);
                zend_hash_str_add(&config_ini_name_map, ini_name.ptr, ini_name.len, &tmp);
            }
        }
    }
}

void zai_config_ini_mshutdown(void) {
    if (GC_REFCOUNT(&config_ini_name_map)) {
        zend_hash_destroy(&config_ini_name_map);
    }
}
