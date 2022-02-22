#ifndef DATADOG_PHP_STRING_VIEW_H
#define DATADOG_PHP_STRING_VIEW_H

#include <stdbool.h>
#include <stddef.h>

/**
 * A string view is a non-owning view into another string. The original string
 * should not be changed from the view.
 *
 * TREAT THIS AS OPAQUE, even though it is not! Use the macros and functions to
 * initialize this struct; DO NOT memzero or rely on defaults like = {} to init
 * this, because we need to guarantee `ptr` is not null to avoid undefined
 * behavior.
 */
typedef struct datadog_php_string_view {
    size_t len;
    const char *ptr;  // must not be null; use "" for empty string
    // it would be great if nonnull attribute worked on struct fields
} datadog_php_string_view;

/* Used to initialize an empty string e.g.
 *   datadog_php_string_view str = DATADOG_PHP_STRING_VIEW_INIT;
 */
#define DATADOG_PHP_STRING_VIEW_INIT \
    { 0, "" }

/* Initialize from a string literal e.g.
 *   datadog_php_string_view str = DATADOG_PHP_STRING_VIEW_LITERAL("hello");
 */
#define DATADOG_PHP_STRING_VIEW_LITERAL(cstr) \
    { sizeof(cstr) - 1, cstr }

/**
 * Converts the C string `cstr` into a string view by getting its length from
 * `strlen`. Null is permitted and will become an empty string.
 * @param cstr May be nullptr.
 */
datadog_php_string_view datadog_php_string_view_from_cstr(const char *cstr);

/**
 * Compares the views `a` and `b` for equality. Note that the `.ptr` value of
 * the views will be ignored when `.len` is 0.
 */
bool datadog_php_string_view_equal(datadog_php_string_view a, datadog_php_string_view b);

#endif  // DATADOG_PHP_STRING_VIEW_H
