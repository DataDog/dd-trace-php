#ifndef ZAI_CONFIG_STABLE_FILE_H
#define ZAI_CONFIG_STABLE_FILE_H

#include <zai_string/string.h>
#include <env/env.h>

typedef enum {
    ZAI_CONFIG_STABLE_FILE_SOURCE_LOCAL,
    ZAI_CONFIG_STABLE_FILE_SOURCE_FLEET,
} zai_config_stable_file_source;

#endif  // ZAI_CONFIG_STABLE_FILE_H

void zai_config_stable_file_minit(void);
void zai_config_stable_file_mshutdown(void);

bool zai_config_stable_file_get_value(zai_str name, zai_env_buffer buf, zai_config_stable_file_source source);
