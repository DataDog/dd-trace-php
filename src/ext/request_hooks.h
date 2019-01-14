#ifndef REQUEST_HOOKS_H
#define REQUEST_HOOKS_H

#include <Zend/zend_types.h>
#include <php.h>

int dd_execute_php_file(const char *filename TSRMLS_DC);

#endif  // REQUEST_HOOKS_H
