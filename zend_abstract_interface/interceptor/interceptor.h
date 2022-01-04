#ifndef ZAI_INTERCEPTOR_H
#define ZAI_INTERCEPTOR_H

#include <main/php.h>
#include <stdbool.h>

typedef void zai_interceptor_caller_owned;

typedef void (*zai_interceptor_begin)(zai_interceptor_caller_owned *ptr, zend_execute_data *execute_data);
typedef void (*zai_interceptor_end)(zai_interceptor_caller_owned *ptr, zend_execute_data *execute_data, zval *retval);

// Invoked only when targets are installed at runtime
typedef zai_interceptor_caller_owned *(*zai_interceptor_dynamic_ptr_ctor)(zai_interceptor_caller_owned *orig_ptr);
typedef void (*zai_interceptor_dynamic_ptr_dtor)(zai_interceptor_caller_owned *ptr);

void zai_interceptor_minit(
    // Begin/end handlers that are used when using static-alloced targets at startup
    zai_interceptor_begin static_begin, zai_interceptor_end static_end,
    // Begin/end handlers that are used when dynamic-alloced targets at runtime
    zai_interceptor_begin dynamic_begin, zai_interceptor_end dynamic_end,
    // Used when converting static targets to dynamic ones
    zai_interceptor_dynamic_ptr_ctor ctor,
    zai_interceptor_dynamic_ptr_dtor dtor
);
void zai_interceptor_mshutdown(void);
void zai_interceptor_rinit(void);
void zai_interceptor_rshutdown(void);

void zai_interceptor_add_target_startup(const char *qualified_name, zai_interceptor_caller_owned *ptr);
zai_interceptor_caller_owned *zai_interceptor_add_target_runtime(const char *class_name, const char *func_name);

#endif  // ZAI_INTERCEPTOR_H
