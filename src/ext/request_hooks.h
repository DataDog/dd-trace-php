#ifndef REQUEST_HOOKS_H
#define REQUEST_HOOKS_H

#include <Zend/zend_types.h>
#include <php.h>

#include "compatibility.h"

int dd_execute_php_file(const char *filename TSRMLS_DC);
int dd_execute_auto_prepend_file(char *auto_prepend_file TSRMLS_DC);
void dd_request_init_hook_rinit(TSRMLS_D);
void dd_request_init_hook_rshutdown(TSRMLS_D);

#endif  // REQUEST_HOOKS_H
