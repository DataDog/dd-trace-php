#include "sapi.h"

#define SV(cstr)                                                               \
  { sizeof(cstr) - 1, cstr }

datadog_php_sapi_type datadog_php_sapi_detect(datadog_php_string_view sapi) {
  if (!sapi.ptr || sapi.len == 0) {
    return DATADOG_PHP_SAPI_UNKNOWN;
  }

  struct {
    datadog_php_string_view str;
    datadog_php_sapi_type type;
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
    if (datadog_php_string_view_eq(sapi, sapis[i].str)) {
      return sapis[i].type;
    }
  }

  return DATADOG_PHP_SAPI_UNKNOWN;
}
