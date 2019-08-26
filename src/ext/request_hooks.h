#ifndef REQUEST_HOOKS_H
#define REQUEST_HOOKS_H

#include <Zend/zend_types.h>
#include <php.h>

#include "compatibility.h"

int ddtrace_load_request_init_hook(TSRMLS_D);

#endif  // REQUEST_HOOKS_H
