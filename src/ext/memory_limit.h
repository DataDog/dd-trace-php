#ifndef DD_TRACE_MEMORY_LIMIT_H
#define DD_TRACE_MEMORY_LIMIT_H

#include <php.h>

#include "compatibility.h"

#define ALLOWED_MAX_MEMORY_USE_IN_PERCENT_OF_MEMORY_LIMIT 0.8

zend_long get_memory_limit(TSRMLS_D);

#endif  // DD_TRACE_MEMORY_LIMIT_H
