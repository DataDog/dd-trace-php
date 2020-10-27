#ifndef DDTRACE_TIME_H
#define DDTRACE_TIME_H

#include <stdint.h>

typedef struct ddtrace_nanotime {
    int64_t count;
} ddtrace_nanotime;

typedef struct ddtrace_microtime {
    int64_t count;
} ddtrace_microtime;

#endif  // DDTRACE_TIME_H
