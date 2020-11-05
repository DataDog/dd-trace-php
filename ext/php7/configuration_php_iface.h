#ifndef DD_CONFIGURATION_PHP_INTERFACE_H
#define DD_CONFIGURATION_PHP_INTERFACE_H
#include <Zend/zend.h>
#include <Zend/zend_types.h>

void ddtrace_php_get_configuration(zval *return_value, zval *zenv_name);

#endif  // DD_CONFIGURATION_PHP_INTERFACE_H
