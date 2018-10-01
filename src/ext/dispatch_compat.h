#ifndef DISPATCH_COMPAT_H
#define DISPATCH_COMPAT_H
#include "Zend/zend_types.h"
#include "compat_zend_string.h"
#include "dispatch.h"

#if PHP_VERSION_ID < 70000
#include "dispatch_compat_php5.h"
void ddtrace_class_lookup_free(void *zv);
#else
void ddtrace_class_lookup_free(zval *zv);
#define INIT_ZVAL(x) ZVAL_NULL(&x)
#endif

void ddtrace_setup_fcall(zend_execute_data *execute_data, zend_fcall_info *fci, zval **result TSRMLS_DC);
zend_function *ddtrace_function_get(const HashTable *table, STRING_T *name);
void ddtrace_dispatch_free_owned_data(ddtrace_dispatch_t *dispatch);
HashTable *ddtrace_new_class_lookup(zend_class_entry *clazz TSRMLS_DC);
zend_bool ddtrace_dispatch_store(HashTable *class_lookup, ddtrace_dispatch_t *dispatch);

#endif  // DISPATCH_COMPAT_H
