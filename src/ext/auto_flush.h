#ifndef DDTRACE_AUTO_FLUSH_H
#define DDTRACE_AUTO_FLUSH_H

#include <php.h>

ZEND_RESULT_CODE ddtrace_flush_tracer(void);

#endif  // DDTRACE_AUTO_FLUSH_H
