#ifndef DD_INTERNAL_FUNCTIONS_H
#define DD_INTERNAL_FUNCTIONS_H

#include "env_config.h"

void ddtrace_hook_internal_functions();

// ext/curl
void ddtrace_init_http_headers(TSRMLS_D);
void ddtrace_destroy_http_headers(TSRMLS_D);
BOOL_T ddtrace_add_http_header(zval *header TSRMLS_DC);

#endif  // DD_INTERNAL_FUNCTIONS_H
