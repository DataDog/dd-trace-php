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
    ZAI_CONFIG_TYPE_STRING,
} zai_config_type;

bool zai_config_decode_value(zai_string_view value, zai_config_type type, zval *decoded_value, bool persistent);

#endif  // ZAI_CONFIG_DECODE_H
