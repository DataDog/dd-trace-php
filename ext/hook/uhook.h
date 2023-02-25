#ifndef ZAI_UHOOK_H
#define ZAI_UHOOK_H

#include <php.h>
#include <sandbox/sandbox.h>

HashTable *dd_uhook_collect_args(zend_execute_data *execute_data);
void dd_uhook_report_sandbox_error(zend_execute_data *execute_data, zend_object *closure, zai_sandbox *sandbox);

void zai_uhook_rinit();
void zai_uhook_rshutdown();
void zai_uhook_minit();
void zai_uhook_mshutdown();

PHP_FUNCTION(trace_function);
PHP_FUNCTION(trace_method);
PHP_FUNCTION(hook_function);
PHP_FUNCTION(hook_method);
PHP_FUNCTION(dd_untrace);
#endif
