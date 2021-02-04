#include "datadog/string.h"

#include <stddef.h>
#include <stdlib.h>
#include <string.h>

datadog_string *datadog_string_alloc(size_t len) {
    // TODO Use datadog/arena.h for strings
    return calloc(1, sizeof(datadog_string) + len + sizeof('\0'));
}

datadog_string *datadog_string_init(const char *str, size_t len) {
    datadog_string *dd_str = datadog_string_alloc(len);
    dd_str->len = len;
    memcpy(dd_str->val, str, len);
    dd_str->val[len] = '\0';
    return dd_str;
}

void datadog_string_free(datadog_string *str) { free(str); }
