#ifndef DDTRACE_PREDICTIVE_CHANGELOG_H
#define DDTRACE_PREDICTIVE_CHANGELOG_H

#include <php.h>

#include "span.h"

typedef enum {
    DDPCL_PHP54,
    DDPCL_PHP55,
    DDPCL_PHP56,
    DDPCL_PHP70,
    DDPCL_PHP71,
    DDPCL_PHP72,
    DDPCL_PHP73,
    DDPCL_PHP74,
    DDPCL_PHP80,
    DDPCL_PHP81,
} ddpcl_php_version;

typedef struct ddpcl_function_breaking_change_s {
    ddpcl_php_version version;
    const char *function_name;
    size_t function_name_len;
    void (*handler)(zend_execute_data *ex, ddtrace_span_t *span);
} ddpcl_function_breaking_change;

void ddtrace_predictive_changelog_replace_internal_functions(void);
void ddtrace_predictive_changelog_rinit(void);

#endif  // DDTRACE_PREDICTIVE_CHANGELOG_H
