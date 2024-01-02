#ifndef ZAI_UHOOK_H
#define ZAI_UHOOK_H

#include <php.h>
#include <sandbox/sandbox.h>

HashTable *dd_uhook_collect_args(zend_execute_data *execute_data);
void dd_uhook_report_sandbox_error(zend_execute_data *execute_data, zend_object *closure);
void dd_uhook_log_invocation(void (*log)(const char *, ...), zend_execute_data *execute_data, const char *type, zend_object *closure);
bool ddtrace_uhook_match_filepath(zend_string *file, zend_string *source);

void zai_uhook_rinit();
void zai_uhook_rshutdown();
void zai_uhook_minit(int module_number);
void zai_uhook_mshutdown();

PHP_FUNCTION(DDTrace_trace_function);
PHP_FUNCTION(DDTrace_trace_method);
PHP_FUNCTION(DDTrace_hook_function);
PHP_FUNCTION(DDTrace_hook_method);
PHP_FUNCTION(dd_untrace);
#endif
