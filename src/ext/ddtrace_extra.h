#ifndef DD_TRACE_EXTRA_H
#define DD_TRACE_EXTRA_H

#include <php.h>
#define ALLOWED_MAX_MEMORY_USE_IN_PERCENT_OF_MEMORY_LIMIT 0.8

PHP_FUNCTION(dd_trace_serialize_msgpack);
PHP_FUNCTION(dd_trace_noop);
PHP_FUNCTION(dd_trace_dd_get_memory_limit);
PHP_FUNCTION(dd_trace_check_memory_under_limit);

#endif  // DD_TRACE_EXTRA_H
