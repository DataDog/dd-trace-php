#ifndef DD_TRACE_EXTRA_H
#define DD_TRACE_EXTRA_H

#include <php.h>

PHP_FUNCTION(dd_trace_serialize_msgpack);
PHP_FUNCTION(dd_trace_noop);
PHP_FUNCTION(dd_trace_dd_get_memory_limit);
// PHP_FUNCTION(dd_trace_check_memory_pressure);

#endif  // DD_TRACE_EXTRA_H
