#ifndef DATADOG_HANDLERS_API_H
#define DATADOG_HANDLERS_API_H

// This api is used by both the tracer and profiler.

#include <php.h>
#include "compatibility.h"

typedef struct datadog_php_zif_handler_s {
    const char *name;
    size_t name_len;
    zif_handler *old_handler;
    zif_handler new_handler;
} datadog_php_zif_handler;

typedef struct datadog_php_zim_handler_s {
    const char *class_name;
    size_t class_name_len;
    datadog_php_zif_handler zif;
} datadog_php_zim_handler;

/**
 * Installs the `handler` if the function represented by `name` + `name_len`
 * is found; otherwise does nothing.
 */
void datadog_php_install_handler(datadog_php_zif_handler handler);
void datadog_php_install_method_handler(datadog_php_zim_handler handler);

#endif  // DDTRACE_HANDLERS_API_H

