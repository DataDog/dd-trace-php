#ifndef ZAI_CONFIG_H
#define ZAI_CONFIG_H

#include <main/php.h>
#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "../zai_string/string.h"
#include "config_decode.h"
#include "config_ini.h"

#define ZAI_CONFIG_ENTRIES_COUNT_MAX 20
#define ZAI_CONFIG_NAMES_COUNT_MAX 4
#define ZAI_CONFIG_NAME_BUFSIZ 42  // TODO Valdate this

#define ZAI_CONFIG_ENTRY(id, name, type, default) \
    { id, ZAI_STRL_VIEW(#name), ZAI_CONFIG_TYPE_##type, ZAI_STRL_VIEW(default), NULL, 0 }

#define ZAI_CONFIG_ALIASED_ENTRY(id, name, type, default, aliases)                         \
    {                                                                                      \
        id, ZAI_STRL_VIEW(#name), ZAI_CONFIG_TYPE_##type, ZAI_STRL_VIEW(default), aliases, \
            (sizeof aliases / sizeof aliases[0])                                           \
    }

typedef uint16_t zai_config_id;

typedef struct zai_config_entry_s {
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
} zai_config_entry;

typedef struct zai_config_name_s {
    size_t len;
    char ptr[ZAI_CONFIG_NAME_BUFSIZ];
} zai_config_name;

typedef struct zai_config_memoized_entry_s {
    zai_config_name names[ZAI_CONFIG_NAMES_COUNT_MAX];
    uint8_t names_count;
    zai_config_type type;
    zval decoded_value;
    // The index of the name that was used to set the value
    //     anything > 0 is deprecated
    //     -1 == not set from env
    int16_t name_index;
} zai_config_memoized_entry;

// Memoizes config entries to default values
// Adds INI defs
// env_to_ini can be NULL to disable INI support
void zai_config_minit(zai_config_entry entries[], size_t entries_count, zai_config_env_to_ini_name env_to_ini,
                      int module_number);
// dtors all pzvals and name maps
// Caller must call UNREGISTER_INI_ENTRIES() after this if using env_to_ini
void zai_config_mshutdown(void);

// Not thread-safe; must block
// Must be called before zai_config_rinit()
// Update decoded_value with env/ini value if exists
void zai_config_first_time_rinit(void);

// Runtime config ctor
//      ZEND_TLS zval runtime_config[ZAI_CONFIG_ENTRIES_COUNT_MAX];
//
// refcount++ of pzvals
void zai_config_rinit(void);
// dtor run-time zvals
void zai_config_rshutdown(void);

void zai_config_update_runtime_config(zai_config_id id, zval *value);

extern uint8_t memoized_entires_count;
extern zai_config_memoized_entry memoized_entires[ZAI_CONFIG_ENTRIES_COUNT_MAX];

// ---
// TODO Remove these
// ---

typedef enum {
    ZAI_CONFIG_SUCCESS,
    ZAI_CONFIG_ERROR_DECODING,
    ZAI_CONFIG_ERROR_INVALID_TYPE,
    /* The function is being called outside of a request context. */
    ZAI_CONFIG_ERROR_NOT_READY,
    /* API usage error. */
    ZAI_CONFIG_ERROR,
} zai_config_result;

// assertions + error_zal
// If caller wants to return to userland: Caller must refcount++ & dtor
zval *zai_config_get_value(zai_config_id id);
zai_config_result zai_config_set_value(zai_config_id id, zval *value);

// Only really used via userland functions
//      \DDTrace\get_config(string $name)
//      \DDTrace\set_config(string $name, mixed $value)
// Caller is responsible for handling post-access config side effects
bool zai_config_get_id_by_name(zai_string_view name, zai_config_id *id);

#endif  // ZAI_CONFIG_H
