#ifndef ZAI_CONFIG_INI_H
#define ZAI_CONFIG_INI_H

#include <main/php.h>
#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "../env/env.h"
#include "../zai_string/string.h"

/* Ideally the size of ZAI_CONFIG_NAME_BUFSIZ with some wiggle room. */
#define ZAI_CONFIG_INI_NAME_BUFSIZ (42 + 32)  // TODO Valdate this

typedef struct zai_config_ini_name_s {
    size_t len;
    char ptr[ZAI_CONFIG_INI_NAME_BUFSIZ];
} zai_config_ini_name;

// Converts the env name to an INI name
// Max buffer: ZAI_CONFIG_INI_NAME_BUFSIZ
typedef void (*zai_config_env_to_ini_name)(zai_string_view env_name, zai_config_ini_name *ini_name);

void zai_config_ini_minit(zai_config_env_to_ini_name env_to_ini, int module_number);
void zai_config_ini_mshutdown(void);

// TODO Remove zai_env_buffer?
bool zai_config_get_ini_value(zai_string_view name, zai_env_buffer buf);

#endif  // ZAI_CONFIG_INI_H
