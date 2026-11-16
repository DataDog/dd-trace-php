#ifndef DDTRACE_STANDALONE_LIMITER_H
#define DDTRACE_STANDALONE_LIMITER_H

#include <stdbool.h>

void ddtrace_standalone_limiter_create(void);
void ddtrace_standalone_limiter_destroy(void);
void ddtrace_standalone_limiter_hit(void);
bool ddtrace_standalone_limiter_allow(void);

#endif  // DDTRACE_STANDALONE_LIMITER_H
