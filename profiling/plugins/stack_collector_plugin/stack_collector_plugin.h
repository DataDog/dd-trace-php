#ifndef DATADOG_PHP_STACK_COLLECTOR_PLUGIN_H
#define DATADOG_PHP_STACK_COLLECTOR_PLUGIN_H

#include <Zend/zend_extensions.h>
#include <stdbool.h>

void datadog_php_stack_collector_startup(zend_extension *extension);
void datadog_php_stack_collector_first_activate(bool profiling_enabled);
void datadog_php_stack_collector_activate(void);
void datadog_php_stack_collector_deactivate(void);

#endif // DATADOG_PHP_STACK_COLLECTOR_PLUGIN_H
