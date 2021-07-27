#include "../config_ini.h"

#include <assert.h>
#include <main/php.h>
#include <stdbool.h>

#define UNUSED(x) (void)(x)

static void (*env_to_ini_name)(zai_string_view env_name, zai_config_name *ini_name);

static bool zai_config_generate_ini_name(zai_string_view name, zai_config_name *ini_name) {
    ini_name->len = 0;
    *ini_name->ptr = '\0';

    env_to_ini_name(name, ini_name);

    ++ini_name->len;  // including trailing zero byte

    return *ini_name->ptr;
}

static int used_original_ini_values = 0;
static char *original_ini_values[ZAI_CONFIG_ENTRIES_COUNT_MAX];

int16_t zai_config_initialize_ini_value(zai_config_name *names, int16_t name_count, zai_string_view *buf, zend_ini_entry **entries) {
    if (!env_to_ini_name) return -1;

    TSRMLS_FETCH();

    int16_t name_index = -1;
    char *runtime_value = NULL;
    uint runtime_value_len;
    char *parsed_ini_value = NULL;
    int parsed_ini_value_len;

    for (int16_t i = 0; i < name_count; ++i) {
        zai_config_name ini_name;
        zai_config_generate_ini_name((zai_string_view) { .len = names[i].len, .ptr = names[i].ptr }, &ini_name);
        int ini_available = zend_hash_find(EG(ini_directives), ini_name.ptr, ini_name.len, (void **) &entries[i]);
        assert(ini_available == SUCCESS);

        if (entries[i]->modified && !runtime_value) {
            runtime_value_len = entries[i]->value_length;
            runtime_value = estrndup(entries[i]->value, runtime_value_len);
        }
        zval *inizv = cfg_get_entry(ini_name.ptr, ini_name.len);
        if (inizv != NULL && !parsed_ini_value) {
            parsed_ini_value = Z_STRVAL_P(inizv);
            parsed_ini_value_len = Z_STRLEN_P(inizv);
            name_index = i;
        }
    }

    for (int16_t i = 0; i < name_count; ++i) {
        char **target = entries[i]->modified ? &entries[i]->orig_value : &entries[i]->value;
        uint *target_len = entries[i]->modified ? &entries[i]->orig_value_length : &entries[i]->value_length;
        if (i > 0) {
            *target_len = entries[0]->modified ? entries[0]->orig_value_length : entries[0]->value_length;
            *target = entries[0]->modified ? entries[0]->orig_value : entries[0]->value;
        } else if (buf->ptr != NULL) {
            *target_len = buf->len;
            *target = original_ini_values[used_original_ini_values++] = zend_strndup(buf->ptr, buf->len);
        } else if (parsed_ini_value != NULL) {
            *target_len = parsed_ini_value_len;
            *target = parsed_ini_value;
        }

        if (runtime_value) {
            if (entries[i]->modified) {
                efree(entries[i]->value);
            } else {
                entries[i]->orig_value_length = entries[i]->value_length;
                entries[i]->orig_value = entries[i]->value;
                entries[i]->modified = true;
            }
            entries[i]->value_length = runtime_value_len;
            entries[i]->value = estrndup(runtime_value, runtime_value_len);
        }
    }

    if (runtime_value) {
        buf->ptr = runtime_value;
        buf->len = runtime_value_len;
        efree(runtime_value);
    } else if (parsed_ini_value && buf->ptr == NULL) {
        buf->ptr = parsed_ini_value;
        buf->len = parsed_ini_value_len;
    }

    return name_index;
}

static ZEND_INI_MH(ZaiConfigOnUpdateIni) {
    UNUSED(mh_arg1);
    UNUSED(mh_arg2);
    UNUSED(mh_arg3);

    /* Ignore calls that happen before runtime (e.g. the default INI values on MINIT). System values are obtained on
     * first-time RINIT. */
    if (stage != PHP_INI_STAGE_RUNTIME) return SUCCESS;

    zai_config_id id;
    zai_string_view name = {.len = entry->name_length, .ptr = entry->name};
    zai_string_view value_view = {.len = new_value_length, .ptr = new_value};

    if (!zai_config_get_id_by_name(name, &id)) {
        // TODO Log cannot find ID
        return FAILURE;
    }

    zval new_zv = zval_used_for_init;
    ZVAL_NULL(&new_zv);

    if (!zai_config_decode_value(value_view, memoized_entires[id].type, &new_zv, /* persistent */ false)) {
        // TODO Log decoding error

        return FAILURE;
    }

    if (memoized_entires[id].ini_change && !memoized_entires[id].ini_change(zai_config_get_value(id), &new_zv)) {
        zval_dtor(&new_zv);
        return FAILURE;
    }

    bool is_reset = new_value == entry->orig_value;
    for (int i = 0; i < memoized_entires[id].names_count; ++i) {
        zend_ini_entry *alias = memoized_entires[id].ini_entries[i];
        if (alias != entry) { // otherwise we leak memory, entry->modified is cached in zend_alter_ini_entry_ex...
            if (alias->modified) {
                efree(alias->value);
            } else {
                alias->modified = true;
                alias->orig_value_length = alias->value_length;
                alias->orig_value = alias->value;
            }
            alias->value_length = new_value_length;
            if (is_reset) {
                alias->value = new_value;
                alias->modified = false;
                alias->orig_value = NULL;
                alias->orig_value_length = 0;
            } else {
                alias->value = estrndup(new_value, new_value_length);
            }
        }
    }

    zai_config_replace_runtime_config(id, &new_zv);
    zval_dtor(&new_zv);
    return SUCCESS;
}

static bool zai_config_add_ini_entry(zai_config_memoized_entry *memoized, zai_string_view name, zai_config_name *ini_name, int module_number) {
    if (!zai_config_generate_ini_name(name, ini_name)) {
        assert(false && "Invalid INI name conversion");
        return false;
    }

    /* ZEND_INI_END() adds a null terminating entry */
    zend_ini_entry entry_defs[1 + /* terminator entry */ 1] = {{0}, {0}};
    zend_ini_entry *entry = &entry_defs[0];

    entry->name = ini_name->ptr;
    entry->name_length = ini_name->len;
    entry->value = (char *) memoized->default_encoded_value.ptr;
    entry->value_length = memoized->default_encoded_value.len;
    entry->on_modify = ZaiConfigOnUpdateIni;
    entry->modifiable = PHP_INI_ALL;
    if (memoized->type == ZAI_CONFIG_TYPE_BOOL) {
        entry->displayer = php_ini_boolean_displayer_cb;
    }

    TSRMLS_FETCH();
    return zend_register_ini_entries(entry_defs, module_number TSRMLS_CC) == SUCCESS;
}

// PHP 5 expects 'static storage duration for ini entry names
static zai_config_name ini_names[ZAI_CONFIG_ENTRIES_COUNT_MAX * ZAI_CONFIG_NAMES_COUNT_MAX];

void zai_config_ini_minit(zai_config_env_to_ini_name env_to_ini, int module_number) {
    env_to_ini_name = env_to_ini;

    if (!env_to_ini_name || !memoized_entires_count) return;

    for (zai_config_id i = 0; i < memoized_entires_count; i++) {
        zai_config_memoized_entry *memoized = &memoized_entires[i];
        for (uint8_t n = 0; n < memoized->names_count; n++) {
            zai_config_name *ini_name = &ini_names[i * ZAI_CONFIG_NAMES_COUNT_MAX + n];
            zai_string_view name = {.len = memoized->names[n].len, .ptr = memoized->names[n].ptr};
            if (zai_config_add_ini_entry(memoized, name, ini_name, module_number)) {
                zai_config_register_config_id(ini_name, i);
            }
        }
    }
}

void zai_config_ini_mshutdown() {
    for (int i = 0; i < used_original_ini_values; ++i) {
        free(original_ini_values[i]);
    }
}
