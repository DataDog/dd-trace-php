#include "../tsrmls_cache.h"

#include <components-rs/library-config.h>

#include "config.h"
#include "config_stable_file.h"

#define RESOLVE_SYMBOL(name) \
    _##name = (void *)DL_FETCH_SYMBOL(ext->handle, #name); \
    if (!_##name) { \
        _ddog_library_configurator_new = NULL; \
        return; \
    }

static struct ddog_Configurator *(*_ddog_library_configurator_new)(bool debug_logs, ddog_CharSlice language);
static void (*_ddog_library_configurator_with_local_path)(struct ddog_Configurator *c, struct ddog_CStr local_path);
static void (*_ddog_library_configurator_with_fleet_path)(struct ddog_Configurator *c, struct ddog_CStr local_path);
static void (*_ddog_library_configurator_with_detect_process_info)(struct ddog_Configurator *c);
static struct ddog_LibraryConfigLoggedResult (*_ddog_library_configurator_get)(const struct ddog_Configurator *configurator);
static struct ddog_CStr (*_ddog_library_config_source_to_string)(enum ddog_LibraryConfigSource name);
static void (*_ddog_library_config_drop)(struct ddog_LibraryConfigLoggedResult);
static void (*_ddog_Error_drop)(struct ddog_Error *error);
static void (*_ddog_library_configurator_drop)(struct ddog_Configurator*);

HashTable *stable_config = NULL;

zai_config_stable_file_entry *zai_config_stable_file_get_value(zai_str name) {
    if (!stable_config) {
        return NULL;
    }

    return zend_hash_str_find_ptr(stable_config, name.ptr, name.len);
}

static void stable_config_entry_dtor(zval *el) {
    zai_config_stable_file_entry *e = (zai_config_stable_file_entry *)Z_PTR_P(el);
    zend_string_release(e->value);
    zend_string_release(e->config_id);
    pefree(e, 1);
}

void zai_config_stable_file_minit(void) {
    // Resolve symbols at runtime, as they are not part of the AppSec extension
    // but are provided by ddtrace if it is loaded.
    if (!_ddog_library_configurator_new) {
        zend_module_entry *ext = NULL;
        ext = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("ddtrace"));
        if (!ext) {
            ext = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("datadog-profiling"));
            if (!ext) {
                return;
            }
        }

        RESOLVE_SYMBOL(ddog_library_configurator_new);
        RESOLVE_SYMBOL(ddog_library_configurator_with_local_path);
        RESOLVE_SYMBOL(ddog_library_configurator_with_fleet_path);
        RESOLVE_SYMBOL(ddog_library_configurator_with_detect_process_info);
        RESOLVE_SYMBOL(ddog_library_configurator_get);
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

    _ddog_library_configurator_with_detect_process_info(configurator);

    ddog_LibraryConfigLoggedResult config_result = _ddog_library_configurator_get(configurator);
    if (config_result.tag == DDOG_LIBRARY_CONFIG_LOGGED_RESULT_OK) {
        stable_config = pemalloc(sizeof(HashTable), 1);
        zend_hash_init(stable_config, 8, NULL, stable_config_entry_dtor, 1);

        ddog_Vec_LibraryConfig configs = config_result.ok.value;
        for (uintptr_t i = 0; i < configs.len; i++) {
            const ddog_LibraryConfig *cfg = &configs.ptr[i];

            zai_config_stable_file_entry *entry = pemalloc(sizeof(zai_config_stable_file_entry), 1);
            entry->value = zend_string_init(cfg->value.ptr, cfg->value.length, 1);
            entry->source = cfg->source;
            entry->config_id = zend_string_init(cfg->config_id.ptr, cfg->config_id.length, 1);

            zend_hash_str_add_ptr(stable_config, cfg->name.ptr, cfg->name.length, entry);
        }
    }

    _ddog_library_config_drop(config_result);
    _ddog_library_configurator_drop(configurator);
}

void zai_config_stable_file_mshutdown(void) {
    if (stable_config) {
        zend_hash_destroy(stable_config);
        pefree(stable_config, 1);
        stable_config = NULL;
    }
}
