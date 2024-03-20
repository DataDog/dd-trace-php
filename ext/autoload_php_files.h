#ifndef REQUEST_HOOKS_H
#define REQUEST_HOOKS_H

#include <Zend/zend_types.h>
#include <php.h>

#include "compatibility.h"

void ddtrace_autoload_minit(void);
#if PHP_VERSION_ID < 80000
void ddtrace_autoload_rinit(void);
#endif
void ddtrace_autoload_rshutdown(void);

#endif  // REQUEST_HOOKS_H
