#ifndef DD_COLLECT_BACKTRACE_H
#define DD_COLLECT_BACKTRACE_H

#include <Zend/zend_types.h>

#define DDTRACE_DEBUG_BACKTRACE_CAPTURE_LOCALS (1 << 30)

void ddtrace_fetch_debug_backtrace(zval *return_value, int skip_last, int options, int limit);
void ddtrace_call_get_locals(zend_execute_data *call, zval *locals_array, bool skip_args);


#endif // DD_COLLECT_BACKTRACE_H
