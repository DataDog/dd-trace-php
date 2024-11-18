#ifndef DDTRACE_STANDALONE_LIMITER_H
#define DDTRACE_STANDALONE_LIMITER_H

#include <stdbool.h>

#include "php.h"

void ddtrace_standalone_limiter_create();
void ddtrace_standalone_limiter_destroy();
void ddtrace_standalone_limiter_hit();
bool ddtrace_standalone_limiter_allow();

#endif  // DDTRACE_STANDALONE_LIMITER_H
