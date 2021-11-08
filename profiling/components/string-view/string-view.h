#ifndef DATADOG_PHP_PROFILER_STRING_VIEW_H
#define DATADOG_PHP_PROFILER_STRING_VIEW_H

#include <stdbool.h>
#include <stddef.h>

typedef struct datadog_php_string_view_s {
  size_t len;

  // `ptr` must be non-null
  const char *ptr;
} datadog_php_string_view;

bool datadog_php_string_view_eq(datadog_php_string_view a,
                                datadog_php_string_view b);

/**
 * Converts the C string `cstr` into a string view by getting its length from
 * `strlen`. Null is permitted and will become an empty string.
 * @param cstr May be nullptr.
 * @return
 */
datadog_php_string_view datadog_php_string_view_from_cstr(const char *cstr);

/**
 * Returns true if the `str` is equal to one of these values:
 *   "1", "on", "yes", "true"
 *
 * The selected values are common for INI parsers in various languages.
 * @param str
 * @return
 */
bool datadog_php_string_view_is_boolean_true(datadog_php_string_view str);

#endif // DATADOG_PHP_PROFILER_STRING_VIEW_H
