#include "../tsrmls_cache.h"

#include <components-rs/library-config.h>

#include "config.h"
#include "config_stable_file.h"

ddog_Configurator *configurator = NULL;

// Due to the current state of libdatadog, the function 'ddog_library_configurator_get' reads and parses the config files on every call.
// In phase 1, as the config won't change between requests, we'll call it only in MINIT to avoid repeated parsing.
// In phase 2, as planned, the call will move to RINIT
ddog_Result_VecLibraryConfig config_result = {0};

bool zai_config_stable_file_get_value(zai_str name, zai_env_buffer buf) {
    ddog_Vec_LibraryConfig configs = config_result.ok;
    for (uintptr_t i = 0; i < configs.len; i++) {
        const ddog_LibraryConfig *cfg = &configs.ptr[i];
        ddog_CStr library_name = ddog_library_config_name_to_env(cfg->name);
        if (strcmp(name.ptr, library_name.ptr) == 0) {
            strcpy(buf.ptr, cfg->value.ptr);
            return true;
        }
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
    if (config_result.tag == DDOG_RESULT_VEC_LIBRARY_CONFIG_ERR_VEC_LIBRARY_CONFIG) {
        return;
    }

    ddog_Vec_LibraryConfig configs = config_result.ok;
    for (uintptr_t i = 0; i < configs.len; i++) {
        const ddog_LibraryConfig *cfg = &configs.ptr[i];
        ddog_CStr name = ddog_library_config_name_to_env(cfg->name);

        zai_config_id config_id;
        zai_str config_name = {.ptr = name.ptr, .len = name.length};
        if (!zai_config_stable_file_find_entry(config_name, &config_id)) {
            continue;
        }

        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[config_id];
        zend_ini_entry *entry = memoized->ini_entries[0]; // FIXME: is [0] OK here?

        zend_string *value = zend_string_init(cfg->value.ptr, cfg->value.length, false);
        ZaiConfigOnUpdateIni(entry, value, NULL, NULL, NULL, stage);
        zend_string_release(value);
    }
}

void zai_config_stable_file_minit(void) {
    configurator = ddog_library_configurator_new(false, DDOG_CHARSLICE_C("php"));

    char *file = getenv("_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE");
    if (file) {
        ddog_CStr path = {.ptr = file, .length = strlen(file)+1}; // FIXME: +1??
        ddog_library_configurator_with_local_path(configurator, path);
    }
    file = getenv("_DD_TEST_LIBRARY_CONFIG_FLEET_FILE");
    if (file) {
        ddog_CStr path = {.ptr = file, .length = strlen(file)+1}; // FIXME: +1??
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

    config_result = ddog_library_configurator_get(configurator);
}

void zai_config_stable_file_mshutdown(void) {
    if (configurator) {
        ddog_library_configurator_drop(configurator);
    }
    if (config_result.tag == DDOG_RESULT_VEC_LIBRARY_CONFIG_ERR_VEC_LIBRARY_CONFIG) {
        ddog_Error err = config_result.err;
        ddog_Error_drop(&err);
    }
}

void zai_config_stable_file_rinit(void) {
    zai_config_stable_file_apply_config(PHP_INI_STAGE_RUNTIME);
}
