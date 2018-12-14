#ifndef DDTRACE_H
#define DDTRACE_H
#include "version.h"
extern zend_module_entry ddtrace_module_entry;

ZEND_BEGIN_MODULE_GLOBALS(ddtrace)
zend_bool disable;
HashTable class_lookup;
HashTable function_lookup;
uint32_t request_counter;
ZEND_END_MODULE_GLOBALS(ddtrace)

#ifdef ZTS
#define DDTRACE_G(v) TSRMG(ddtrace_globals_id, zend_ddtrace_globals *, v)
#else
#define DDTRACE_G(v) (ddtrace_globals.v)
#endif

#define PHP_DDTRACE_EXTNAME "ddtrace"
#ifndef PHP_DDTRACE_VERSION
#define PHP_DDTRACE_VERSION "0.0.0-unknown"
#endif

#endif  // DDTRACE_H
