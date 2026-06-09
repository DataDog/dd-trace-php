#ifndef DDTRACE_HANDLERS_EXCEPTION_H
#define DDTRACE_HANDLERS_EXCEPTION_H

#include "php.h"

zend_object *ddtrace_find_active_exception(void);

#endif // DDTRACE_HANDLERS_EXCEPTION_H
