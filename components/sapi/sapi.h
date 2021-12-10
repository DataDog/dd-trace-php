#ifndef DATADOG_PHP_SAPI_H
#define DATADOG_PHP_SAPI_H

#include <components/string_view/string_view.h>

typedef enum {
    DATADOG_PHP_SAPI_UNKNOWN = 0,
    DATADOG_PHP_SAPI_APACHE2HANDLER,
    DATADOG_PHP_SAPI_CGI_FCGI,
    DATADOG_PHP_SAPI_CLI,
    DATADOG_PHP_SAPI_CLI_SERVER,
    DATADOG_PHP_SAPI_EMBED,
    DATADOG_PHP_SAPI_LITESPEED,
    DATADOG_PHP_SAPI_FPM_FCGI,
    DATADOG_PHP_SAPI_PHPDBG,
} datadog_php_sapi;

datadog_php_sapi datadog_php_sapi_from_name(datadog_php_string_view module);
datadog_php_sapi datadog_php_sapi_detect(datadog_php_string_view module);

#endif  // DATADOG_PHP_SAPI_H
