#ifndef ZAI_CONFIG_H
#define ZAI_CONFIG_H

#include <main/php.h>
#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "../zai_string/string.h"
#include "config_decode.h"

typedef struct zai_config_entry_s zai_config_entry;
typedef struct zai_config_name_s zai_config_name;
typedef struct zai_config_memoized_entry_s zai_config_memoized_entry;

typedef uint16_t zai_config_id;

#include "config_ini.h"

#define ZAI_CONFIG_ENTRIES_COUNT_MAX 128
#define ZAI_CONFIG_NAMES_COUNT_MAX 4
#define ZAI_CONFIG_NAME_BUFSIZ 60

#define ZAI_CONFIG_ENTRY(_id, _name, _type, default, ...)                          \
    {                                                                              \
        .id = _id, .name = ZAI_STRL_VIEW(#_name), .type = ZAI_CONFIG_TYPE_##_type, \
        .default_encoded_value = ZAI_STRL_VIEW(default), ##__VA_ARGS__,            \
    }

#define ZAI_CONFIG_ALIASED_ENTRY(id, name, type, default, _aliases, ...) \
    ZAI_CONFIG_ENTRY(id, name, type, default, .aliases = _aliases,       \
                     .aliases_count = sizeof(_aliases) / sizeof(zai_string_view), ##__VA_ARGS__)

struct zai_config_entry_s {
    zai_config_id id;
    // Env name
    zai_string_view name;
    zai_config_type type;
    zai_string_view default_encoded_value;
    // Alias env names in order of precedence:
    // (e.g. DD_SERVICE_NAME, DD_TRACE_APP_NAME, ddtrace_app_name)
    // TODO: Drop old names
    zai_string_view *aliases;
    uint8_t aliases_count;
    // Accept or reject ini changes, potentially apply to the currently running system
    zai_config_apply_ini_change ini_change;
};

struct zai_config_name_s {
    size_t len;
    char ptr[ZAI_CONFIG_NAME_BUFSIZ];
};

struct zai_config_memoized_entry_s {
    zai_config_name names[ZAI_CONFIG_NAMES_COUNT_MAX];
    zend_ini_entry *ini_entries[ZAI_CONFIG_NAMES_COUNT_MAX];
    uint8_t names_count;
    zai_config_type type;
    zval decoded_value;
    zai_string_view default_encoded_value;
    // The index of the name that was used to set the value
    //     anything > 0 is deprecated
    //     -1 == not set from env or system ini
    int16_t name_index;
    zai_config_apply_ini_change ini_change;
};

// Memoizes config entries to default values
// Adds INI defs
// env_to_ini can be NULL to disable INI support
bool zai_config_minit(zai_config_entry entries[], size_t entries_count, zai_config_env_to_ini_name env_to_ini,
                      int module_number);
// dtors all pzvals and name maps
// Caller must call UNREGISTER_INI_ENTRIES() after this if using env_to_ini
void zai_config_mshutdown(void);

// Not thread-safe; must block (Use pthread_once)
// Must be called before zai_config_rinit()
// Update decoded_value with env/ini value if exists
void zai_config_first_time_rinit(void);

// Runtime config ctor (++rc)
void zai_config_rinit(void);
// dtor run-time zvals  (--rc)
void zai_config_rshutdown(void);

// Directly replace the config value for the current request. Copies the passed argument.
void zai_config_replace_runtime_config(zai_config_id id, zval *value);

extern uint8_t zai_config_memoized_entries_count;
extern zai_config_memoized_entry zai_config_memoized_entries[ZAI_CONFIG_ENTRIES_COUNT_MAX];

// assertions + error_zal
// If caller wants to return to userland: Caller must refcount++ & dtor
zval *zai_config_get_value(zai_config_id id);

bool zai_config_get_id_by_name(zai_string_view name, zai_config_id *id);

// Adds name to name<->id mapping. Id may be present multiple times.
void zai_config_register_config_id(zai_config_name *name, zai_config_id id);

#endif  // ZAI_CONFIG_H
