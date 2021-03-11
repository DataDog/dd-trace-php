#ifndef DATADOG_PHP_STRING_VIEW_H
#define DATADOG_PHP_STRING_VIEW_H

#include <stdbool.h>
#include <stddef.h>

/**
 * A string view is a non-owning view into another string. The original string
 * should not be changed from the view.
 */
typedef struct datadog_php_string_view {
    size_t len;
    const char *ptr;
} datadog_php_string_view;

/* Used to initialize an empty string e.g.
 *   datadog_php_string_view str = DATADOG_PHP_STRING_VIEW_INIT;
 */
#define DATADOG_PHP_STRING_VIEW_INIT \
    { 0, NULL }

/* Initialize from a string literal e.g.
 *   datadog_php_string_view str = DATADOG_PHP_STRING_VIEW_LITERAL("hello");
 */
#define DATADOG_PHP_STRING_VIEW_LITERAL(cstr) \
    { sizeof(cstr) - 1, cstr }

/**
 * Creates a string view from a C string, which is an array of char which is
 * terminated by a null byte. Derives the length from strlen. `cstr` may be
 * null, in which case the `.len` will be 0.
 */
datadog_php_string_view datadog_php_string_view_from_cstr(const char cstr[]);

/**
 * Compares the views `a` and `b` for equality. Note that the `.ptr` value of
 * the views will be ignored when `.len` is 0.
 */
bool datadog_php_string_view_equal(datadog_php_string_view a, datadog_php_string_view b);

#endif  // DATADOG_PHP_STRING_VIEW_H
