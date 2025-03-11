#ifndef ZAI_CONFIG_STABLE_FILE_H
#define ZAI_CONFIG_STABLE_FILE_H

#include <zai_string/string.h>
#include <env/env.h>

#endif  // ZAI_CONFIG_STABLE_FILE_H

void zai_config_stable_file_minit(void);
void zai_config_stable_file_mshutdown(void);

void zai_config_stable_file_rinit(void);
bool zai_config_stable_file_get_value(zai_str name, zai_env_buffer buf);
