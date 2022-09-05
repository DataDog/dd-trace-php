#ifndef ZAI_INTERCEPTOR_H
#define ZAI_INTERCEPTOR_H

#include <Zend/zend_compile.h>

void zai_interceptor_op_array_ctor(zend_op_array *op_array);
void zai_interceptor_op_array_pass_two(zend_op_array *op_array);

void zai_interceptor_startup(zend_module_entry *module_entry);
void zai_interceptor_activate(void);
void zai_interceptor_rinit(void);
void zai_interceptor_deactivate(void);
void zai_interceptor_shutdown(void);

void zai_interceptor_terminate_all_pending_observers(void);

#endif  // ZAI_INTERCEPTOR_H
