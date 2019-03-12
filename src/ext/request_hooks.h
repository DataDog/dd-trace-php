#ifndef REQUEST_HOOKS_H
#define REQUEST_HOOKS_H

#include <Zend/zend_types.h>
#include <php.h>

int dd_execute_php_file(const char *filename TSRMLS_DC);
int dd_no_blacklisted_modules(char *blacklist_regexp);

#endif  // REQUEST_HOOKS_H
