#ifndef TEA_EXT_PROFILING_H
#define TEA_EXT_PROFILING_H

#include <Zend/zend_portability.h>
#include <Zend/zend_types.h>
#include <stdint.h>

BEGIN_EXTERN_C()
#include <components/stack-sample/stack-sample.h>

ZEND_API datadog_php_stack_sample tea_get_last_stack_sample(void);
ZEND_API void datadog_profiling_interrupt_function(zend_execute_data *execute_data);
END_EXTERN_C()

#endif
