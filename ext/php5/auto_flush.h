#ifndef DDTRACE_AUTO_FLUSH_H
#define DDTRACE_AUTO_FLUSH_H

#include <php.h>
#include <stdbool.h>

bool ddtrace_flush_tracer(TSRMLS_D);

#endif  // DDTRACE_AUTO_FLUSH_H
