#ifndef DDTRACE_H
#define DDTRACE_H
extern zend_module_entry ddtrace_module_entry;

ZEND_BEGIN_MODULE_GLOBALS(ddtrace)
	zend_bool   disable;
	HashTable	dispatch_lookup;
ZEND_END_MODULE_GLOBALS(ddtrace)

#ifdef ZTS
#define DDTRACE(v) TSRMG(ddtrace_globals_id, zend_ddtrace_globals *, v)
#else
#define DDTRACE(v) (ddtrace_globals.v)
#endif

#define PHP_DDTRACE_EXTNAME "ddtrace"
#define PHP_DDTRACE_VERSION "0.0.1"

#include "Zend/zend_inheritance.h"
#include "Zend/zend_exceptions.h"
#include "Zend/zend_closures.h"

#endif //DDTRACE_H