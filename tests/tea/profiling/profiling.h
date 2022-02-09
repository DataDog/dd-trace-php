#ifndef TEA_EXT_PROFILING_H
#define TEA_EXT_PROFILING_H

#include <Zend/zend_portability.h>
#include <stdint.h>

BEGIN_EXTERN_C()
#include <components/stack-sample/stack-sample.h>

ZEND_API datadog_php_stack_sample tea_get_last_stack_sample(void);
END_EXTERN_C()

#endif
