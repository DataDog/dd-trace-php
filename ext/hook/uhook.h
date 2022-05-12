#ifndef ZAI_UHOOK_H
#define ZAI_UHOOK_H

HashTable *dd_uhook_collect_args(zend_execute_data *execute_data);

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
