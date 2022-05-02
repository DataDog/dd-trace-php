#include "../config_ini.h"

#include <SAPI.h>
#include <assert.h>
#include <main/php.h>
#include <stdbool.h>

#define UNUSED(x) (void)(x)

static void (*env_to_ini_name)(zai_string_view env_name, zai_config_name *ini_name);
static bool is_fpm = false;

static bool zai_config_generate_ini_name(zai_string_view name, zai_config_name *ini_name) {
    ini_name->len = 0;
    *ini_name->ptr = '\0';

    env_to_ini_name(name, ini_name);

    return *ini_name->ptr;
}

#if ZTS
// we need to prevent race conditions between copying the inis and setting the global inis during first rinit
static pthread_rwlock_t lock_ini_init_rw = PTHREAD_RWLOCK_INITIALIZER;
static tsrm_thread_end_func_t original_thread_end_handler;

static void zai_config_lock_ini_copying(THREAD_T thread_id) {
    pthread_rwlock_rdlock(&lock_ini_init_rw);
    original_thread_end_handler(thread_id);
    pthread_rwlock_unlock(&lock_ini_init_rw);
}
#endif

// values retrieved here are assumed to be valid
int16_t zai_config_initialize_ini_value(zend_ini_entry **entries, int16_t ini_count, zai_string_view *buf,
                                        zai_string_view default_value, zai_config_id entry_id) {
    UNUSED(entry_id);

    if (!env_to_ini_name) return -1;

#if ZTS
    pthread_rwlock_wrlock(&lock_ini_init_rw);
#endif

    int16_t name_index = -1;
    zend_string *runtime_value = NULL;
    zend_string *parsed_ini_value = NULL;

    if (is_fpm) {
        for (int16_t i = 0; i < ini_count; ++i) {
            // Unconditional assignment of inis, bypassing any APIs for random ini values is very much not nice
            // Try working around ...
            zend_string *ini_str = entries[i]->modified ? entries[i]->orig_value : entries[i]->value;
            if (ZSTR_LEN(ini_str) != default_value.len || strcmp(ZSTR_VAL(ini_str), default_value.ptr) != 0) {
                parsed_ini_value = zend_string_copy(ini_str);
                name_index = i;
                break;
            }
        }
    }

    for (int16_t i = 0; i < ini_count; ++i) {
        if (entries[i]->modified && !runtime_value) {
            runtime_value = zend_string_copy(entries[i]->value);
        }
        zval *inizv = cfg_get_entry(ZSTR_VAL(entries[i]->name), ZSTR_LEN(entries[i]->name));
        if (inizv != NULL && !parsed_ini_value) {
            parsed_ini_value = zend_string_copy(Z_STR_P(inizv));
            name_index = i;
        }
    }

    for (int16_t i = 0; i < ini_count; ++i) {
        bool duplicate = false;
        for (int j = i + 1; j < ini_count; ++j) {
            if (entries[i] == entries[j]) {
                duplicate = true;
            }
        }
        if (duplicate) {
            continue;
        }

        zend_string **target = entries[i]->modified ? &entries[i]->orig_value : &entries[i]->value;
        if (i > 0) {
            zend_string_release(*target);
            *target = zend_string_copy(entries[0]->modified ? entries[0]->orig_value : entries[0]->value);
        } else if (buf->ptr != NULL) {
            zend_string_release(*target);
            *target = zend_string_init(buf->ptr, buf->len, 1);
        } else if (parsed_ini_value != NULL) {
            zend_string_release(*target);
            *target = zend_string_copy(parsed_ini_value);
        }

        if (runtime_value) {
            if (entries[i]->modified) {
                if (entries[i]->value != entries[i]->orig_value) {
                    zend_string_release(entries[i]->value);
                }
            } else {
                entries[i]->orig_value = entries[i]->value;
                entries[i]->modified = true;
                entries[i]->orig_modifiable = entries[i]->modifiable;
                zend_hash_add_ptr(EG(modified_ini_directives), entries[i]->name, entries[i]);
            }
            entries[i]->value = zend_string_copy(runtime_value);
        }
    }

    if (runtime_value) {
        buf->ptr = ZSTR_VAL(runtime_value);
        buf->len = ZSTR_LEN(runtime_value);
        zend_string_release(runtime_value);
    } else if (parsed_ini_value && buf->ptr == NULL) {
        buf->ptr = ZSTR_VAL(parsed_ini_value);
        buf->len = ZSTR_LEN(parsed_ini_value);
    }

    if (parsed_ini_value) {
        zend_string_release(parsed_ini_value);
    }

#if ZTS
    pthread_rwlock_unlock(&lock_ini_init_rw);
#endif

    return name_index;
}

static ZEND_INI_MH(ZaiConfigOnUpdateIni) {
    UNUSED(mh_arg1);
    UNUSED(mh_arg2);
    UNUSED(mh_arg3);

    // ensure validity at any stage
    zai_config_id id;
    zai_string_view name = {.len = ZSTR_LEN(entry->name), .ptr = ZSTR_VAL(entry->name)};
    zai_string_view value_view = {.len = ZSTR_LEN(new_value), .ptr = ZSTR_VAL(new_value)};

    if (!zai_config_get_id_by_name(name, &id)) {
        // TODO Log cannot find ID
        return FAILURE;
    }

    zval new_zv;
    ZVAL_UNDEF(&new_zv);
    zai_config_memoized_entry *memoized = &zai_config_memoized_entries[id];

    if (!zai_config_decode_value(value_view, memoized->type, &new_zv, /* persistent */ stage < PHP_INI_STAGE_RUNTIME)) {
        // TODO Log decoding error

        return FAILURE;
    }

    /* This forces ini update before runtime stage to be ignored. */
    if (stage < PHP_INI_STAGE_RUNTIME) {
        zai_config_dtor_pzval(&new_zv);
        return SUCCESS;
    }

    /* We continue for >= runtime changes: INI_STAGE_HTACCESS > INI_STAGE_RUNTIME */

    if (memoized->ini_change && !memoized->ini_change(zai_config_get_value(id), &new_zv)) {
        zval_dtor(&new_zv);
        return FAILURE;
    }

    bool is_reset = zend_string_equals(new_value, entry->orig_value);
    for (int i = 0; i < memoized->names_count; ++i) {
        zend_ini_entry *alias = zend_hash_find_ptr(
            EG(ini_directives), memoized->ini_entries[i]->name);  // alias initially contains the global ini
        if (alias != entry) {  // otherwise we leak memory, entry->modified is cached in zend_alter_ini_entry_ex...
            if (alias->modified) {
                zend_string_release(alias->value);
            } else {
                alias->modified = true;
                alias->orig_value = alias->value;
                alias->orig_modifiable = alias->modifiable;
                zend_hash_add_ptr(EG(modified_ini_directives), alias->name, alias);
            }
            if (is_reset) {
                alias->value = entry->orig_value;
                alias->modified = false;
                alias->orig_value = NULL;
            } else {
                alias->value = zend_string_copy(new_value);
            }
        }
    }

    zai_config_replace_runtime_config(id, &new_zv);
    zval_dtor(&new_zv);
    return SUCCESS;
}

static void zai_config_add_ini_entry(zai_config_memoized_entry *memoized, zai_string_view name,
                                     zai_config_name *ini_name, int module_number, zai_config_id id) {
    if (!zai_config_generate_ini_name(name, ini_name)) {
        assert(false && "Invalid INI name conversion");
        return;
    }

    zai_config_id duplicate;
    if (zai_config_get_id_by_name((zai_string_view){.ptr = ini_name->ptr, .len = ini_name->len}, &duplicate)) {
        return;
    }

    zai_config_register_config_id(ini_name, id);

    /* ZEND_INI_END() adds a null terminating entry */
    zend_ini_entry_def entry_defs[1 + /* terminator entry */ 1] = {{0}, {0}};
    zend_ini_entry_def *entry = &entry_defs[0];

    entry->name = ini_name->ptr;
    entry->name_length = ini_name->len;
    entry->value = memoized->default_encoded_value.ptr;
    entry->value_length = memoized->default_encoded_value.len;
    entry->on_modify = ZaiConfigOnUpdateIni;
    entry->modifiable = memoized->ini_change == zai_config_system_ini_change ? PHP_INI_SYSTEM : PHP_INI_ALL;
    if (memoized->type == ZAI_CONFIG_TYPE_BOOL) {
        entry->displayer = php_ini_boolean_displayer_cb;
    }

    if (zend_register_ini_entries(entry_defs, module_number) == FAILURE) {
        // This is not really recoverable ...
        assert(0 && "All our ini entries have been removed due to a single duplicate :-(");
    }
}

// PHP 5 expects 'static storage duration for ini entry names
zai_config_name ini_names[ZAI_CONFIG_ENTRIES_COUNT_MAX * ZAI_CONFIG_NAMES_COUNT_MAX];

void zai_config_ini_minit(zai_config_env_to_ini_name env_to_ini, int module_number) {
    env_to_ini_name = env_to_ini;

    is_fpm = strlen(sapi_module.name) == sizeof("fpm-fcgi") - 1 && !strcmp(sapi_module.name, "fpm-fcgi");

    if (!env_to_ini_name) return;

    for (zai_config_id i = 0; i < zai_config_memoized_entries_count; ++i) {
        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[i];
        for (uint8_t n = 0; n < memoized->names_count; ++n) {
            zai_config_name *ini_name = &ini_names[i * ZAI_CONFIG_NAMES_COUNT_MAX + n];
            zai_string_view name = {.len = memoized->names[n].len, .ptr = memoized->names[n].ptr};
            zai_config_add_ini_entry(memoized, name, ini_name, module_number, i);
            // We need to cache ini directives here, at least for ZTS in order to access the global inis
            memoized->ini_entries[n] = zend_hash_str_find_ptr(EG(ini_directives), ini_name->ptr, ini_name->len);
            assert(memoized->ini_entries[n] != NULL);
        }
    }

#if ZTS
    original_thread_end_handler = tsrm_set_new_thread_end_handler(zai_config_lock_ini_copying);
#endif
}

#if ZTS
void zai_config_ini_rinit() {
    if (!env_to_ini_name) return;

    // we have to cover two cases here:
    // a) update ini tables to take changes during first-time rinit into account
    // b) apply and verify user.ini/htaccess settings
    for (uint8_t i = 0; i < zai_config_memoized_entries_count; ++i) {
        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[i];
        bool applied_update = false;
        for (uint8_t n = 0; n < memoized->names_count; ++n) {
            zend_ini_entry *source = memoized->ini_entries[n],
                           *ini = zend_hash_find_ptr(EG(ini_directives), source->name);
            if (ini->modified) {
                if (ini->orig_value == ini->value) {
                    ini->value = source->value;
                }
                zend_string_release(ini->orig_value);
                ini->orig_value = zend_string_copy(source->value);

                if (!applied_update) {
                    if (ZaiConfigOnUpdateIni(ini, ini->value, NULL, NULL, NULL, PHP_INI_STAGE_RUNTIME) == SUCCESS) {
                        // first encountered name has highest priority
                        applied_update = true;
                    } else {
                        zend_string_release(ini->value);
                        ini->value = ini->orig_value;
                        ini->modified = false;
                        ini->orig_value = NULL;
                    }
                }
            } else {
                zend_string_release(ini->value);
                ini->value = zend_string_copy(source->value);
            }
        }
    }
}
#endif

void zai_config_ini_mshutdown() {}
