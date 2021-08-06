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

    ++ini_name->len;  // including trailing zero byte

    return *ini_name->ptr;
}

#if ZTS
// we need to prevent race conditions between copying the inis and setting the global inis during first rinit
static pthread_rwlock_t lock_ini_init_rw = PTHREAD_RWLOCK_INITIALIZER;
static tsrm_thread_end_func_t original_thread_end_handler;

static void zai_config_lock_ini_copying(THREAD_T thread_id, void ***tsrm_ls) {
    pthread_rwlock_rdlock(&lock_ini_init_rw);
    original_thread_end_handler(thread_id, tsrm_ls);
    pthread_rwlock_unlock(&lock_ini_init_rw);
}
#endif

static int used_original_ini_values = 0;
static char *original_ini_values[ZAI_CONFIG_ENTRIES_COUNT_MAX];

// values retrieved here are assumed to be valid
int16_t zai_config_initialize_ini_value(zend_ini_entry **entries, int16_t ini_count, zai_string_view *buf,
                                        zai_string_view default_value) {
    if (!env_to_ini_name) return -1;

#if ZTS
    pthread_rwlock_wrlock(&lock_ini_init_rw);
#endif

    zai_config_ini_mshutdown();  // reset in the event of a re-init

    int16_t name_index = -1;
    char *runtime_value = NULL;
    uint runtime_value_len = 0;
    char *parsed_ini_value = NULL;
    int parsed_ini_value_len = 0;

    if (is_fpm) {
        for (int16_t i = 0; i < ini_count; ++i) {
            // Unconditional assignment of inis, bypassing any APIs for random ini values is very much not nice
            // Try working around ...
            char *val = entries[i]->modified ? entries[i]->orig_value : entries[i]->value;
            uint val_len = entries[i]->modified ? entries[i]->orig_value_length : entries[i]->value_length;
            if (val_len - 1 != default_value.len || !strcmp(val, default_value.ptr)) {
                parsed_ini_value = val;
                parsed_ini_value_len = (int)val_len;
                name_index = i;
                break;
            }
        }
    }

    for (int16_t i = 0; i < ini_count; ++i) {
        if (entries[i]->modified && !runtime_value) {
            runtime_value_len = entries[i]->value_length;
            runtime_value = estrndup(entries[i]->value, runtime_value_len);
        }
        zval *inizv = cfg_get_entry(entries[i]->name, entries[i]->name_length);
        if (inizv != NULL && !parsed_ini_value) {
            parsed_ini_value = Z_STRVAL_P(inizv);
            parsed_ini_value_len = Z_STRLEN_P(inizv);
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

        bool update_pointer_value = entries[i]->value == entries[i]->orig_value;
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
        if (update_pointer_value) {
            entries[i]->value = *target;
        }

        if (runtime_value) {
            if (entries[i]->modified) {
                if (entries[i]->value != entries[i]->orig_value) {
                    efree(entries[i]->value);
                }
            } else {
                entries[i]->orig_value_length = entries[i]->value_length;
                entries[i]->orig_value = entries[i]->value;
                entries[i]->modified = true;
                entries[i]->orig_modifiable = entries[i]->modifiable;
#if !ZTS
                zend_hash_add(EG(modified_ini_directives), entries[i]->name, entries[i]->name_length, &entries[i],
                              sizeof(zend_ini_entry *), NULL);
#endif
            }
            entries[i]->value_length = runtime_value_len;
            entries[i]->value = estrndup(runtime_value, runtime_value_len);
        }
    }

    if (runtime_value) {
        efree(runtime_value);
        buf->ptr = entries[0]->value;
        buf->len = entries[0]->value_length;
    } else if (parsed_ini_value && buf->ptr == NULL) {
        buf->ptr = parsed_ini_value;
        buf->len = parsed_ini_value_len;
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
    zai_string_view name = {.len = entry->name_length, .ptr = entry->name};
    zai_string_view value_view = {.len = new_value_length, .ptr = new_value};

    if (!zai_config_get_id_by_name(name, &id)) {
        // TODO Log cannot find ID
        return FAILURE;
    }

    zval new_zv = zval_used_for_init;
    ZVAL_NULL(&new_zv);

    if (!zai_config_decode_value(value_view, zai_config_memoized_entries[id].type, &new_zv,
                                 /* persistent */ stage != PHP_INI_STAGE_RUNTIME)) {
        // TODO Log decoding error

        return FAILURE;
    }

    /* Ignore calls that happen before runtime (e.g. the default INI values on MINIT). System values are obtained on
     * first-time RINIT. */
    if (stage != PHP_INI_STAGE_RUNTIME) {
        zai_config_dtor_pzval(&new_zv);
        return SUCCESS;
    }

    if (zai_config_memoized_entries[id].ini_change &&
        !zai_config_memoized_entries[id].ini_change(zai_config_get_value(id), &new_zv)) {
        zval_dtor(&new_zv);
        return FAILURE;
    }

    bool is_reset = new_value == entry->orig_value;
    for (int i = 0; i < zai_config_memoized_entries[id].names_count; ++i) {
        zend_ini_entry *alias = zai_config_memoized_entries[id].ini_entries[i];
#if ZTS
        // alias initially contains the global ini
        zend_hash_find(EG(ini_directives), alias->name, alias->name_length, (void **)&alias);
#endif
        if (alias != entry) {  // otherwise we leak memory, entry->modified is cached in zend_alter_ini_entry_ex...
            if (alias->modified) {
                efree(alias->value);
            } else {
                alias->modified = true;
                alias->orig_value_length = alias->value_length;
                alias->orig_value = alias->value;
                alias->orig_modifiable = alias->modifiable;
                zend_hash_add(EG(modified_ini_directives), alias->name, alias->name_length, &alias,
                              sizeof(zend_ini_entry *), NULL);
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

static void zai_config_add_ini_entry(zai_config_memoized_entry *memoized, zai_string_view name,
                                     zai_config_name *ini_name, int module_number, zai_config_id id TSRMLS_DC) {
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
    zend_ini_entry entry_defs[1 + /* terminator entry */ 1] = {{0}, {0}};
    zend_ini_entry *entry = &entry_defs[0];

    entry->name = ini_name->ptr;
    entry->name_length = ini_name->len;
    entry->value = (char *)memoized->default_encoded_value.ptr;
    entry->value_length = memoized->default_encoded_value.len;
    entry->on_modify = ZaiConfigOnUpdateIni;
    entry->modifiable = memoized->ini_change == zai_config_system_ini_change ? PHP_INI_SYSTEM : PHP_INI_ALL;
    if (memoized->type == ZAI_CONFIG_TYPE_BOOL) {
        entry->displayer = php_ini_boolean_displayer_cb;
    }

    if (zend_register_ini_entries(entry_defs, module_number TSRMLS_CC) == FAILURE) {
        // This is not really recoverable ...
        assert(0 && "All our ini entries have been removed due to a single duplicate :-(");
    }
}

// PHP 5 expects 'static storage duration for ini entry names
static zai_config_name ini_names[ZAI_CONFIG_ENTRIES_COUNT_MAX * ZAI_CONFIG_NAMES_COUNT_MAX];

void zai_config_ini_minit(zai_config_env_to_ini_name env_to_ini, int module_number) {
    env_to_ini_name = env_to_ini;

    is_fpm = strlen(sapi_module.name) == sizeof("fpm-fcgi") - 1 && !strcmp(sapi_module.name, "fpm-fcgi");

    if (!env_to_ini_name) return;

    TSRMLS_FETCH();

    for (zai_config_id i = 0; i < zai_config_memoized_entries_count; i++) {
        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[i];
        for (uint8_t n = 0; n < memoized->names_count; n++) {
            zai_config_name *ini_name = &ini_names[i * ZAI_CONFIG_NAMES_COUNT_MAX + n];
            zai_string_view name = {.len = memoized->names[n].len, .ptr = memoized->names[n].ptr};
            zai_config_add_ini_entry(memoized, name, ini_name, module_number, i TSRMLS_CC);
            int ini_available =
                zend_hash_find(EG(ini_directives), ini_name->ptr, ini_name->len, (void **)&memoized->ini_entries[n]);
            UNUSED(ini_available);  // compiled away asserts cause compile errors otherwise...
            assert(ini_available == SUCCESS);
        }
    }

#if ZTS
    original_thread_end_handler = tsrm_set_new_thread_end_handler(zai_config_lock_ini_copying);
#endif
}

#if ZTS
void zai_config_ini_rinit() {
    if (!env_to_ini_name) return;

    TSRMLS_FETCH();

    // we have to cover two cases here:
    // a) update ini tables to take changes during first-time rinit into account
    // b) apply and verify user.ini/htaccess settings
    for (uint8_t i = 0; i < zai_config_memoized_entries_count; ++i) {
        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[i];
        bool applied_update = false;
        for (uint8_t n = 0; n < memoized->names_count; ++n) {
            zend_ini_entry *source = memoized->ini_entries[n], *ini;
            zend_hash_find(EG(ini_directives), source->name, source->name_length, (void **)&ini);
            if (ini->modified) {
                if (ini->orig_value == ini->value) {
                    ini->value = source->value;
                }
                ini->orig_value = source->value;
                ini->orig_value_length = source->value_length;

                if (!applied_update) {
                    if (ZaiConfigOnUpdateIni(ini, ini->value, ini->value_length, NULL, NULL, NULL,
                                             PHP_INI_STAGE_RUNTIME TSRMLS_CC) == SUCCESS) {
                        // first encountered name has highest priority
                        applied_update = true;
                    } else {
                        efree(ini->value);
                        ini->value = ini->orig_value;
                        ini->value_length = ini->orig_value_length;
                        ini->modified = false;
                        ini->orig_value = NULL;
                    }
                }
            } else {
                ini->value = source->value;
                ini->value_length = source->value_length;
            }
        }
    }
}
#endif

void zai_config_ini_mshutdown() {
    used_original_ini_values = 0;
    for (int i = 0; i < used_original_ini_values; ++i) {
        free(original_ini_values[i]);
    }
}
