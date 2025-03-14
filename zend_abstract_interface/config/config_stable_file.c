#include "../tsrmls_cache.h"

#include <components-rs/library-config.h>

#include "config.h"
#include "config_stable_file.h"

HashTable *config_values = NULL;

bool zai_config_stable_file_get_value(zai_str name, zai_env_buffer buf) {
    if (!config_values) {
        return false;
    }

    char *value = zend_hash_str_find_ptr(config_values, name.ptr, name.len);
    if (value) {
        strcpy(buf.ptr, value);
        return true;
    }

    return false;
}

static bool zai_config_stable_file_find_entry(zai_str config_name, zai_config_id *id) {
    for (zai_config_id i = 0; i < zai_config_memoized_entries_count; ++i) {
        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[i];
        for (uint8_t n = 0; n < memoized->names_count; ++n) {
            zai_str name = ZAI_STR_NEW(memoized->names[n].ptr, memoized->names[n].len);
            if (strcmp(name.ptr, config_name.ptr) == 0) {
                *id = i;
                return true;
            }
        }
    }

    return false;
}

static void zai_config_stable_file_apply_config(int stage) {
    if (!config_values) {
        return;
    }

    zend_string *key;
    void *value;
    ZEND_HASH_FOREACH_STR_KEY_PTR(config_values, key, value) {
        zai_config_id config_id;
        zai_str config_name = {.ptr = ZSTR_VAL(key), .len = ZSTR_LEN(key)};

        if (!zai_config_stable_file_find_entry(config_name, &config_id)) {
            continue;
        }

        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[config_id];
        zend_ini_entry *entry = memoized->ini_entries[0];

        zend_string *value_str = zend_string_init(value, strlen(value), false);
        zend_alter_ini_entry_ex(entry->name, value_str, PHP_INI_USER, stage, 0);
        zend_string_release(value_str);
    } ZEND_HASH_FOREACH_END();
}

static void config_value_dtor(zval *zv) {
    void *ptr = Z_PTR_P(zv);
    pefree(ptr, 1);
}

void zai_config_stable_file_minit(void) {
    ddog_Configurator *configurator = ddog_library_configurator_new(false, DDOG_CHARSLICE_C("php"));

    char *file = getenv("_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE");
    if (file) {
        ddog_CStr path = {.ptr = file, .length = strlen(file)+1}; // FIXME: +1 -> https://github.com/DataDog/libdatadog/pull/924
        ddog_library_configurator_with_local_path(configurator, path);
    }
    file = getenv("_DD_TEST_LIBRARY_CONFIG_FLEET_FILE");
    if (file) {
        ddog_CStr path = {.ptr = file, .length = strlen(file)+1}; // FIXME: +1 -> https://github.com/DataDog/libdatadog/pull/924
        ddog_library_configurator_with_fleet_path(configurator, path);
    }

    // FIXME: without the call to ddog_library_configurator_with_process_info,
    // some AppSec's integration tests fails
#define DDOG_SLICE_CHARSLICE(arr) \
    ((ddog_Slice_CharSlice){.ptr = arr, .len = sizeof(arr) / sizeof(arr[0])})

    ddog_CharSlice args[] = {
        DDOG_CHARSLICE_C("/usr/bin/php"),
    };

    ddog_CharSlice envp[] = {
        DDOG_CHARSLICE_C("FOO=BAR"),
    };
    ddog_ProcessInfo process_info = {
        .args = DDOG_SLICE_CHARSLICE(args),
        .envp = DDOG_SLICE_CHARSLICE(envp),
        .language = DDOG_CHARSLICE_C("php")
    };
    ddog_library_configurator_with_process_info(configurator, process_info);
    //

    ddog_Result_VecLibraryConfig config_result = ddog_library_configurator_get(configurator);
    if (config_result.tag == DDOG_RESULT_VEC_LIBRARY_CONFIG_OK_VEC_LIBRARY_CONFIG) {
        config_values = pemalloc(sizeof(HashTable), 1);
        zend_hash_init(config_values, 8, NULL, config_value_dtor, 1);

        ddog_Vec_LibraryConfig configs = config_result.ok;
        for (uintptr_t i = 0; i < configs.len; i++) {
            const ddog_LibraryConfig *cfg = &configs.ptr[i];
            ddog_CStr env_name = ddog_library_config_name_to_env(cfg->name);

            char *value = pestrndup(cfg->value.ptr, cfg->value.length, 1);
            if (!value) {
                continue;
            }
            zend_hash_str_add_ptr(config_values, env_name.ptr, env_name.length + 1, value);  // FIXME: +1 -> https://github.com/DataDog/libdatadog/pull/924
        }
        ddog_library_config_drop(configs);
    } else {
        ddog_Error err = config_result.err;
        ddog_Error_drop(&err);
    }

    ddog_library_configurator_drop(configurator);
}

void zai_config_stable_file_mshutdown(void) {
    if (config_values) {
        zend_hash_destroy(config_values);
        pefree(config_values, 1);
        config_values = NULL;
    }
}

void zai_config_stable_file_rinit(void) {
    zai_config_stable_file_apply_config(PHP_INI_STAGE_RUNTIME);
}
