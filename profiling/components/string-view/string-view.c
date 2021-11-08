#include "string-view.h"

#include <string.h>

typedef datadog_php_string_view string_view_t;

bool datadog_php_string_view_eq(string_view_t a, string_view_t b) {
  return a.len == b.len && (a.ptr == b.ptr || memcmp(a.ptr, b.ptr, b.len) == 0);
}

datadog_php_string_view datadog_php_string_view_from_cstr(const char *cstr) {
  if (cstr) {
    return (datadog_php_string_view){.len = strlen(cstr), .ptr = cstr};
  } else {
    return (datadog_php_string_view){.len = 0, .ptr = ""};
  }
}

bool datadog_php_string_view_is_boolean_true(string_view_t str) {
  size_t len = str.len;
  if (len > 0 && len < 5) {
    const char *truthy[] = {"1", "on", "yes", "true"};
    // Conveniently, by pure luck len - 1 is the index for that string.
    return memcmp(str.ptr, truthy[len - 1], len) == 0;
  } else {
    return false;
  }
}
