#ifndef HAVE_DDTRACE_LIMITER_H
#define HAVE_DDTRACE_LIMITER_H

#include "php.h"
#include "../configuration.h"

void ddtrace_limiter_create();
bool ddtrace_limiter_active();
bool ddtrace_limiter_allow();
double ddtrace_limiter_rate();
void ddtrace_limiter_destroy();
#endif
