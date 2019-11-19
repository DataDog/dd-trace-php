#ifndef DD_ENGINE_HOOKS_H
#define DD_ENGINE_HOOKS_H

#include <php.h>
#include <stdint.h>

void ddtrace_opcode_minit(void);
void ddtrace_opcode_mshutdown(void);
int ddtrace_opcode_default_dispatch(zend_execute_data *execute_data TSRMLS_DC);

void ddtrace_compile_minit(void);
void ddtrace_compile_mshutdown(void);
void ddtrace_compile_time_reset(TSRMLS_D);
int64_t ddtrace_compile_time_get(TSRMLS_D);

#endif  // DD_ENGINE_HOOKS_H
