#ifndef DATADOG_PHP_STACK_COLLECTOR_H
#define DATADOG_PHP_STACK_COLLECTOR_H

#include <components/stack-sample/stack-sample.h>

typedef struct _zend_execute_data zend_execute_data;

void datadog_php_stack_collect(zend_execute_data *, datadog_php_stack_sample *);

#endif // DATADOG_PHP_STACK_COLLECTOR_H
