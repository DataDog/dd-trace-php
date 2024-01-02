#ifndef ZAI_CONFIG_INI_H
#define ZAI_CONFIG_INI_H

#include <main/php.h>
#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "../env/env.h"
#include "../zai_string/string.h"
#include "config.h"

#endif  // ZAI_CONFIG_INI_H

// Converts the env name to an INI name
// Max buffer: ZAI_CONFIG_NAME_BUFSIZ
typedef void (*zai_config_env_to_ini_name)(zai_str env_name, zai_config_name *ini_name);

void zai_config_ini_minit(zai_config_env_to_ini_name env_to_ini, int module_number);
void zai_config_ini_rinit();
void zai_config_ini_mshutdown();

/* If present environment variable always overrides original value
 * otherwise the first found user specified ini value will be used
 * in case none is found the system default is preserved
 *
 * However e.g. with perdir ini values (e.g. user.ini or php_admin_flag in .htaccess) are also runtime ini
 * but these get applied before first time rinit. So we need to find the highest priority ini value
 * and apply these as runtime config to all other values
 */
int16_t zai_config_initialize_ini_value(zend_ini_entry **entries,
                                        int16_t ini_count,
                                        zai_option_str *buf,
                                        zai_str default_value,
                                        zai_config_id entry_id);

typedef bool (*zai_config_apply_ini_change)(zval *old_value, zval *new_value, zend_string *new_str);
typedef bool (*zai_env_config_fallback)(zai_env_buffer buf, bool pre_rinit);

bool zai_config_system_ini_change(zval *old_value, zval *new_value, zend_string *new_str);

bool zai_config_is_modified(zai_config_id entry_id);
