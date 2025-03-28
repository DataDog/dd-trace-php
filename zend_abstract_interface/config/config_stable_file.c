#include "../tsrmls_cache.h"

#include <components-rs/library-config.h>

#include "config.h"
#include "config_stable_file.h"

#define RESOLVE_SYMBOL(name) \
    _##name = (void *)DL_FETCH_SYMBOL(ddtrace_me->handle, #name); \
    if (!_##name) { \
        _ddog_library_configurator_new = NULL; \
        return; \
    }

static struct ddog_Configurator *(*_ddog_library_configurator_new)(bool debug_logs, ddog_CharSlice language);
static void (*_ddog_library_configurator_with_local_path)(struct ddog_Configurator *c, struct ddog_CStr local_path);
static void (*_ddog_library_configurator_with_fleet_path)(struct ddog_Configurator *c, struct ddog_CStr local_path);
static void (*_ddog_library_configurator_with_process_info)(struct ddog_Configurator *c, struct ddog_ProcessInfo p);
static struct ddog_Result_VecLibraryConfig (*_ddog_library_configurator_get)(const struct ddog_Configurator *configurator);
static struct ddog_CStr (*_ddog_library_config_source_to_string)(enum ddog_LibraryConfigSource name);
static struct ddog_CStr (*_ddog_library_config_name_to_env)(enum ddog_LibraryConfigName name);
static void (*_ddog_library_config_drop)(struct ddog_Vec_LibraryConfig);
static void (*_ddog_Error_drop)(struct ddog_Error *error);
static void (*_ddog_library_configurator_drop)(struct ddog_Configurator*);

HashTable *local_config_values = NULL;
HashTable *fleet_config_values = NULL;

bool zai_config_stable_file_get_value(zai_str name, zai_env_buffer buf, zai_config_stable_file_source source) {
    HashTable *store = (source == ZAI_CONFIG_STABLE_FILE_SOURCE_LOCAL) ? local_config_values : fleet_config_values;
    if (!store) {
        return false;
    }

    zval *value = zend_hash_str_find(store, name.ptr, name.len);
    if (value) {
        strcpy(buf.ptr, Z_STRVAL_P(value));
        return true;
    }

    return false;
}

void zai_config_stable_file_minit(void) {
    // Resolve symbols at runtime, as they are not part of the AppSec extension
    // but are provided by ddtrace if it is loaded.
    if (!_ddog_library_configurator_new) {
        zend_module_entry *ddtrace_me = NULL;
        ddtrace_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("ddtrace"));
        if (!ddtrace_me) {
            return;
        }

        RESOLVE_SYMBOL(ddog_library_configurator_new);
        RESOLVE_SYMBOL(ddog_library_configurator_with_local_path);
        RESOLVE_SYMBOL(ddog_library_configurator_with_fleet_path);
        RESOLVE_SYMBOL(ddog_library_configurator_with_process_info);
        RESOLVE_SYMBOL(ddog_library_configurator_get);
        RESOLVE_SYMBOL(ddog_library_config_name_to_env);
        RESOLVE_SYMBOL(ddog_library_config_source_to_string);
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
        local_config_values = pemalloc(sizeof(HashTable), 1);
        zend_hash_init(local_config_values, 8, NULL, ZVAL_INTERNAL_PTR_DTOR, 1);
        fleet_config_values = pemalloc(sizeof(HashTable), 1);
        zend_hash_init(fleet_config_values, 8, NULL, ZVAL_INTERNAL_PTR_DTOR, 1);

        ddog_Vec_LibraryConfig configs = config_result.ok;
        for (uintptr_t i = 0; i < configs.len; i++) {
            const ddog_LibraryConfig *cfg = &configs.ptr[i];
            ddog_CStr env_name = _ddog_library_config_name_to_env(cfg->name);
            ddog_CStr source = _ddog_library_config_source_to_string(cfg->source);

            zend_string *value = zend_string_init(cfg->value.ptr, cfg->value.length, 1);
            zval zv;
            ZVAL_STR(&zv, value);

            HashTable *store = local_config_values;
            if (strcmp(source.ptr, "fleet_stable_config") == 0) {
                store = fleet_config_values;
            }

            zend_hash_str_add(store, env_name.ptr, env_name.length, &zv);
        }
        _ddog_library_config_drop(configs);
    } else {
        ddog_Error err = config_result.err;
        _ddog_Error_drop(&err);
    }

    _ddog_library_configurator_drop(configurator);
}

void zai_config_stable_file_mshutdown(void) {
    if (local_config_values) {
        zend_hash_destroy(local_config_values);
        pefree(local_config_values, 1);
        local_config_values = NULL;
    }
    if (fleet_config_values) {
        zend_hash_destroy(fleet_config_values);
        pefree(fleet_config_values, 1);
        fleet_config_values = NULL;
    }
}
