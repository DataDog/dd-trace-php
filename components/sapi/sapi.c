#include "sapi.h"

typedef datadog_php_sapi sapi_t;
typedef datadog_php_string_view string_view_t;

#define SV(cstr) DATADOG_PHP_STRING_VIEW_LITERAL(cstr)

sapi_t datadog_php_sapi_from_name(string_view_t module) {
    if (!module.ptr || module.len == 0) {
        return DATADOG_PHP_SAPI_UNKNOWN;
    }

    struct {
        string_view_t str;
        sapi_t type;
    } sapis[] = {
        {SV("apache2handler"), DATADOG_PHP_SAPI_APACHE2HANDLER},
        {SV("cgi-fcgi"), DATADOG_PHP_SAPI_CGI_FCGI},
        {SV("cli"), DATADOG_PHP_SAPI_CLI},
        {SV("cli-server"), DATADOG_PHP_SAPI_CLI_SERVER},
        {SV("embed"), DATADOG_PHP_SAPI_EMBED},
        {SV("fpm-fcgi"), DATADOG_PHP_SAPI_FPM_FCGI},
        {SV("litespeed"), DATADOG_PHP_SAPI_LITESPEED},
        {SV("phpdbg"), DATADOG_PHP_SAPI_PHPDBG},
    };

    unsigned n_sapis = sizeof sapis / sizeof *sapis;
    for (unsigned i = 0; i != n_sapis; ++i) {
        if (datadog_php_string_view_equal(module, sapis[i].str)) {
            return sapis[i].type;
        }
    }

    return DATADOG_PHP_SAPI_UNKNOWN;
}

datadog_php_sapi datadog_php_sapi_detect(datadog_php_string_view module) { return datadog_php_sapi_from_name(module); }
