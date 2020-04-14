#include "auto_flush.h"

#include <php.h>

#if PHP_VERSION_ID < 50500
int ddtrace_flush_tracer(void) { return SUCCESS; }
#else
ZEND_RESULT_CODE ddtrace_flush_tracer(void) { return SUCCESS; }
#endif
