#include "../tsrmls_cache.h"

#include <components-rs/library-config.h>

#include "config.h"
#include "config_stable_file.h"

ddog_Configurator *configurator = NULL;

// Due to the current state of libdatadog, the function 'ddog_library_configurator_get' reads and parses the config files on every call.
// In phase 1, as the config won't change between requests, we'll call it only in MINIT to avoid repeated parsing.
// In phase 2, as planned, the call will move to RINIT
ddog_Result_VecLibraryConfig config_result = {0};

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

void zai_config_stable_file_rinit(void) {
    if (config_result.tag == DDOG_RESULT_VEC_LIBRARY_CONFIG_ERR_VEC_LIBRARY_CONFIG) {
        return;
    }

    ddog_Vec_LibraryConfig configs = config_result.ok;
    for (uintptr_t i = 0; i < configs.len; i++) {
        const ddog_LibraryConfig *cfg = &configs.ptr[i];
        ddog_CStr name = ddog_library_config_name_to_env(cfg->name);

        zai_config_id config_id;
        zai_str config_name = {.ptr = name.ptr, .len = name.length};
        if (zai_config_stable_file_find_entry(config_name, &config_id)) {
            zval new_zv;
            ZVAL_UNDEF(&new_zv);
            zai_config_memoized_entry *memoized = &zai_config_memoized_entries[config_id];

            zai_str config_value = {.ptr = cfg->value.ptr, .len = cfg->value.length};
            if (zai_config_decode_value(config_value, memoized->type, memoized->parser, &new_zv, /* persistent */ false)) {
                zai_config_replace_runtime_config(config_id, &new_zv);
                zval_ptr_dtor(&new_zv);
            }
        }
    }
}
