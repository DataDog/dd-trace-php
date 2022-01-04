#ifndef ZAI_FUNCTION_CALL_INTERCEPTOR_H
#define ZAI_FUNCTION_CALL_INTERCEPTOR_H

#include <main/php.h>
#include <stdbool.h>

#include "../zai_string/string.h"

typedef void (*zai_fci_prehook)(zend_execute_data *execute_data);
typedef void (*zai_fci_posthook)(zend_execute_data *execute_data, zval *retval);

typedef struct zai_fci_target_s {
    void *runtime_hook;
    zai_fci_prehook prehook;
    zai_fci_posthook posthook;
} zai_fci_target;

// PHP lifecycle hooks
void zai_fci_minit(void);
void zai_fci_mshutdown(void);
void zai_fci_rinit(void);
void zai_fci_rshutdown(void);

// Module-startup hooks
bool zai_fci_startup_prehook(const char *qualified_name, zai_fci_prehook prehook);
bool zai_fci_startup_posthook(const char *qualified_name, zai_fci_posthook posthook);
// Request-scoped hooks (using dispatch)
bool zai_fci_runtime_hook_ex(zend_string class_name, zend_string func_name, void *runtime_hook);

#endif  // ZAI_FUNCTION_CALL_INTERCEPTOR_H
