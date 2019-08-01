#ifndef DISPATCH_H
#define DISPATCH_H

#include <Zend/zend_types.h>
#include <php.h>
#include <stdint.h>

#include "compat_zend_string.h"

typedef struct _ddtrace_dispatch_t {
    zval callable, function_name;
    zend_bool busy;
    uint32_t acquired;
} ddtrace_dispatch_t;

typedef struct _ddtrace_lookup_data_t {
#if PHP_VERSION_ID < 70000
    const char *function_name;
    uint32_t function_name_length;
#else
    zend_string *function_name;
#endif
} ddtrace_lookup_data_t;

inline static char * _lookup_data_function_name(ddtrace_lookup_data_t *lookupdata) {
    #if PHP_VERSION_ID < 70000
        return lookupdata->function_name;
    #else
        return ZSTR_VAL(lookupdata->function_name);
    #endif
}

inline static uint32_t _lookup_data_function_name_length(ddtrace_lookup_data_t *lookupdata) {
    #if PHP_VERSION_ID < 70000
        if (lookupdata->function_name_length == 0 && lookupdata->function_name){
            lookupdata->function_name_length = strlen(lookupdata->function_name);
        }
        return lookupdata->function_name_length;
    #else
        return ZSTR_LEN(lookupdata->function_name);
    #endif
}

zend_bool ddtrace_trace(zval *, zval *, zval *TSRMLS_DC);
int ddtrace_wrap_fcall(zend_execute_data *TSRMLS_DC);
void ddtrace_class_lookup_acquire(ddtrace_dispatch_t *);
void ddtrace_class_lookup_release(ddtrace_dispatch_t *);
zend_class_entry *ddtrace_target_class_entry(zval *, zval *TSRMLS_DC);
int ddtrace_find_function(HashTable *table, zval *name, zend_function **function);
void ddtrace_dispatch_init(TSRMLS_D);
void ddtrace_dispatch_inject(TSRMLS_D);
void ddtrace_dispatch_destroy(TSRMLS_D);
void ddtrace_dispatch_reset(TSRMLS_D);

#endif  // DISPATCH_H
