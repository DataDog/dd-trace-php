#ifndef DD_COMPILE_H
#define DD_COMPILE_H

#include <php.h>
#include <stdint.h>

void ddtrace_compile_hook();
void ddtrace_compile_unhook();
void ddtrace_compile_time_reset(TSRMLS_D);
uint32_t ddtrace_compile_time_get(TSRMLS_D);

#endif  // DD_COMPILE_H
