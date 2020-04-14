#ifndef DDTRACE_AUTO_FLUSH_H
#define DDTRACE_AUTO_FLUSH_H

#include <php.h>

#if PHP_VERSION_ID < 50500
int ddtrace_flush_tracer(void);
#else
ZEND_RESULT_CODE ddtrace_flush_tracer(void);
#endif

#endif  // DDTRACE_AUTO_FLUSH_H
