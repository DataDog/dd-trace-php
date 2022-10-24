#ifndef REQUEST_HOOKS_H
#define REQUEST_HOOKS_H

#include <Zend/zend_types.h>
#include <php.h>

#include "compatibility.h"

int dd_execute_php_file(const char *filename);
int dd_execute_auto_prepend_file(char *auto_prepend_file);
void dd_request_init_hook_rinit(void);
void dd_request_init_hook_rshutdown(void);

#endif  // REQUEST_HOOKS_H
