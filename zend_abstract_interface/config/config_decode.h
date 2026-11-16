#ifndef ZAI_CONFIG_DECODE_H
#define ZAI_CONFIG_DECODE_H

#include <main/php.h>
#include <stdbool.h>

#include "../zai_string/string.h"

typedef enum {
    ZAI_CONFIG_TYPE_BOOL,
    ZAI_CONFIG_TYPE_DOUBLE,
    ZAI_CONFIG_TYPE_INT,
    ZAI_CONFIG_TYPE_MAP,
    ZAI_CONFIG_TYPE_SET,
    ZAI_CONFIG_TYPE_SET_LOWERCASE,
    ZAI_CONFIG_TYPE_SET_OR_MAP_LOWERCASE,
    ZAI_CONFIG_TYPE_JSON,
    ZAI_CONFIG_TYPE_STRING,
    ZAI_CONFIG_TYPE_CUSTOM,
} zai_config_type;

typedef bool (*zai_custom_parse)(zai_str value, zval *decoded_value, bool persistent);
typedef void (*zai_custom_display)(zend_ini_entry *ini_entry, int type);

bool zai_config_decode_value(zai_str value, zai_config_type type, zai_custom_parse custom_parser, zval *decoded_value, bool persistent);

#endif  // ZAI_CONFIG_DECODE_H
