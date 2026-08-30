#ifndef DATADOG_PHP_PROFILING_LIFECYCLE_H
#define DATADOG_PHP_PROFILING_LIFECYCLE_H

#include <php.h>
#include <Zend/zend_extensions.h>

extern const zend_function_entry *ddog_php_prof_functions;

size_t ddog_php_prof_globals_size(void);
void ddog_php_prof_ginit(void *globals);
void ddog_php_prof_gshutdown(void *globals);

int ddog_php_prof_minit(int type, int module_number);
int ddog_php_prof_mshutdown(int type, int module_number);
int ddog_php_prof_rinit(int type, int module_number);
int ddog_php_prof_rshutdown(int type, int module_number);
bool ddog_php_prof_is_enabled(void);
int ddog_php_prof_post_deactivate(void);
void ddog_php_prof_minfo(zend_module_entry *module);

int ddog_php_prof_zend_startup(zend_extension *extension);
void ddog_php_prof_zend_activate(void);
void ddog_php_prof_zend_shutdown(zend_extension *extension);

#endif
