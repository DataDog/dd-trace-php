// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#include "configuration_shared.h"

#include <string.h>

bool datadog_config_parse_tags(zai_str value, zval *decoded_value, bool persistent) {
    ZVAL_ARR(decoded_value, pemalloc(sizeof(HashTable), persistent));
    zend_hash_init(Z_ARR_P(decoded_value), 8, NULL, persistent ? ZVAL_INTERNAL_PTR_DTOR : ZVAL_PTR_DTOR, persistent);

    if (value.len == 0) {
        return true;
    }

    const char *str = value.ptr;
    const char *end = str + value.len;
    const char *current = str;

    /* Prefer comma-separated tags when the input contains a comma. */
    char separator = memchr(str, ',', value.len) ? ',' : ' ';

    while (current < end) {
        while (current < end && (*current == ' ' || *current == separator)) {
            current++;
        }
        if (current == end) {
            break;
        }

        const char *tag_end = memchr(current, separator, end - current);
        if (!tag_end) {
            tag_end = end;
        }

        const char *key_start = current;
        const char *key_end = tag_end;
        const char *value_start = tag_end;
        const char *value_end = tag_end;
        const char *colon = memchr(current, ':', tag_end - current);
        if (colon) {
            key_end = colon;
            value_start = colon + 1;
        }

        while (key_start < key_end && *key_start == ' ') {
            key_start++;
        }
        while (key_end > key_start && key_end[-1] == ' ') {
            key_end--;
        }

        if (key_start != key_end) {
            while (value_start < value_end && *value_start == ' ') {
                value_start++;
            }
            while (value_end > value_start && value_end[-1] == ' ') {
                value_end--;
            }

            zend_string *key = zend_string_init(key_start, key_end - key_start, persistent);
            zval decoded_tag_value;
            ZVAL_STR(&decoded_tag_value,
                     zend_string_init(value_start, value_end - value_start, persistent));
            /* OpenTelemetry attributes have unique keys. Retain the last value,
             * matching the tracer's historical DD_TAGS behavior. */
            zend_hash_update(Z_ARRVAL_P(decoded_value), key, &decoded_tag_value);
            zend_string_release(key);
        }

        current = tag_end + 1;
    }

    return true;
}
