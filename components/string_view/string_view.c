#include "string_view.h"

#include <string.h>

datadog_php_string_view datadog_php_string_view_from_cstr(const char *cstr) {
    if (cstr) {
        return (datadog_php_string_view){.len = strlen(cstr), .ptr = cstr};
    } else {
        return (datadog_php_string_view){.len = 0, .ptr = ""};
    }
}

bool datadog_php_string_view_equal(datadog_php_string_view a, datadog_php_string_view b) {
    return a.len == b.len && (a.ptr == b.ptr || memcmp(a.ptr, b.ptr, b.len) == 0);
}
