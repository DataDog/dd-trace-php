#include "../tsrmls_cache.h"

#include <components-rs/library-config.h>

#include "config.h"
#include "config_stable_file.h"

#ifdef _WIN32
    #include <windows.h>
    #define RESOLVE_SYMBOL(name) \
        _##name = (void *)GetProcAddress(GetModuleHandle(NULL), #name); \
        if (!_##name) { \
            _ddog_library_configurator_new = NULL; \
            return; \
        }
#else
    #include <dlfcn.h>
    #define RESOLVE_SYMBOL(name) \
        _##name = (void *)dlsym(RTLD_DEFAULT, #name); \
        if (!_##name) { \
            _ddog_library_configurator_new = NULL; \
            return; \
        }
#endif

static struct ddog_Configurator *(*_ddog_library_configurator_new)(bool debug_logs, ddog_CharSlice language);
static void (*_ddog_library_configurator_with_local_path)(struct ddog_Configurator *c, struct ddog_CStr local_path);
static void (*_ddog_library_configurator_with_fleet_path)(struct ddog_Configurator *c, struct ddog_CStr local_path);
static void (*_ddog_library_configurator_with_process_info)(struct ddog_Configurator *c, struct ddog_ProcessInfo p);
static struct ddog_Result_VecLibraryConfig (*_ddog_library_configurator_get)(const struct ddog_Configurator *configurator);
static struct ddog_CStr (*_ddog_library_config_name_to_env)(enum ddog_LibraryConfigName name);
static void (*_ddog_library_config_drop)(struct ddog_Vec_LibraryConfig);
static void (*_ddog_Error_drop)(struct ddog_Error *error);
static void (*_ddog_library_configurator_drop)(struct ddog_Configurator*);

HashTable *config_values = NULL;

bool zai_config_stable_file_get_value(zai_str name, zai_env_buffer buf) {
    if (!config_values) {
        return false;
    }

    zval *value = zend_hash_str_find(config_values, name.ptr, name.len);
    if (value) {
        strcpy(buf.ptr, Z_STRVAL_P(value));
        return true;
    }

    return false;
}

static void zai_config_stable_file_apply_config(int stage) {
    if (!config_values) {
        return;
    }

    zend_string *key;
    zval *value;
    ZEND_HASH_FOREACH_STR_KEY_VAL(config_values, key, value) {
        zai_config_id config_id;
        zai_str config_name = {.ptr = ZSTR_VAL(key), .len = ZSTR_LEN(key)};
        if (!zai_config_get_id_by_name(config_name, &config_id)) {
            continue;
        }

        zai_config_memoized_entry *memoized = &zai_config_memoized_entries[config_id];
        zend_ini_entry *entry = memoized->ini_entries[0];
        zend_alter_ini_entry_ex(entry->name, Z_STR_P(value), PHP_INI_USER, stage, 0);
    } ZEND_HASH_FOREACH_END();
}

void zai_config_stable_file_minit(void) {
    // Resolve symbols at runtime, as they are not part of the AppSec extension
    // but are provided by ddtrace if it is loaded.
    if (!_ddog_library_configurator_new) {
        RESOLVE_SYMBOL(ddog_library_configurator_new);
        RESOLVE_SYMBOL(ddog_library_configurator_with_local_path);
        RESOLVE_SYMBOL(ddog_library_configurator_with_fleet_path);
        RESOLVE_SYMBOL(ddog_library_configurator_with_process_info);
        RESOLVE_SYMBOL(ddog_library_configurator_get);
        RESOLVE_SYMBOL(ddog_library_config_name_to_env);
        RESOLVE_SYMBOL(ddog_library_config_drop);
        RESOLVE_SYMBOL(ddog_Error_drop);
        RESOLVE_SYMBOL(ddog_library_configurator_drop);
    }

    ddog_Configurator *configurator = _ddog_library_configurator_new(false, DDOG_CHARSLICE_C("php"));

    char *file = getenv("_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE");
    if (file) {
        ddog_CStr path = {.ptr = file, .length = strlen(file)};
        _ddog_library_configurator_with_local_path(configurator, path);
    }
    file = getenv("_DD_TEST_LIBRARY_CONFIG_FLEET_FILE");
    if (file) {
        ddog_CStr path = {.ptr = file, .length = strlen(file)};
        _ddog_library_configurator_with_fleet_path(configurator, path);
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
    _ddog_library_configurator_with_process_info(configurator, process_info);
    //

    ddog_Result_VecLibraryConfig config_result = _ddog_library_configurator_get(configurator);
    if (config_result.tag == DDOG_RESULT_VEC_LIBRARY_CONFIG_OK_VEC_LIBRARY_CONFIG) {
        config_values = pemalloc(sizeof(HashTable), 1);
        zend_hash_init(config_values, 8, NULL, ZVAL_INTERNAL_PTR_DTOR, 1);

        ddog_Vec_LibraryConfig configs = config_result.ok;
        for (uintptr_t i = 0; i < configs.len; i++) {
            const ddog_LibraryConfig *cfg = &configs.ptr[i];
            ddog_CStr env_name = _ddog_library_config_name_to_env(cfg->name);

            zend_string *value = zend_string_init(cfg->value.ptr, cfg->value.length, 1);
            zval zv;
            ZVAL_STR(&zv, value);
            zend_hash_str_add(config_values, env_name.ptr, env_name.length, &zv);
        }
        _ddog_library_config_drop(configs);
    } else {
        ddog_Error err = config_result.err;
        _ddog_Error_drop(&err);
    }

    _ddog_library_configurator_drop(configurator);
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
