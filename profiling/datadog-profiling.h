#ifndef DATADOG_PHP_PROFILING_H
#define DATADOG_PHP_PROFILING_H

#include <Zend/zend_extensions.h>
#include <Zend/zend_modules.h>
#include <components/string_view/string_view.h>
#include <stdbool.h>

/**
 * Returns true if the `str` is equal to one of these values:
 *   "1", "on", "yes", "true"
 *
 * The selected values are common for INI parsers in various languages.
 * @param str
 * @return
 */
bool datadog_php_string_view_is_boolean_true(datadog_php_string_view str);

ZEND_COLD void datadog_profiling_info_diagnostics_row(const char *col_a,
                                                      const char *col_b);

#endif // DATADOG_PHP_PROFILING_H
