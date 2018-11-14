#ifndef DISPATCH_COMPAT_PHP5_H
#define DISPATCH_COMPAT_PHP5_H
#if PHP_VERSION_ID < 70000
#include "Zend/zend_types.h"
#include "compat_zend_string.h"
#include "debug.h"
#include "dispatch.h"

#define FBC() EX(fbc)
#define OBJECT() EX(object)
// #define NUM_ADDITIONAL_ARGS() EX(call)->num_additional_args
#define NUM_ADDITIONAL_ARGS() (0)

static zend_always_inline void *zend_hash_str_find_ptr(const HashTable *ht, const char *key, size_t length) {
    void **rv = NULL;
    zend_hash_find(ht, key, length, (void **)&rv);

    if (rv) {
        return *rv;
    } else {
        return NULL;
    }
}

#undef EX
#define EX(x) ((execute_data)->x)

static zend_always_inline zend_function *datadog_current_function(zend_execute_data *execute_data) {
    if (EX(opline)->opcode == ZEND_DO_FCALL_BY_NAME) {
        return FBC();
    } else {
        return EX(function_state).function;
    }
}

static zend_always_inline zval *datadog_this(zend_function *current_function, zend_execute_data *execute_data) {
    if (!current_function->common.scope) {
        return NULL;
    }

    // return EX(call) ? EX(call)->object : NULL;
    return EX(object);
}

#undef EX
#define EX(x) ((execute_data).x)
#endif  // PHP_VERSION_ID < 70000
#endif  // DISPATCH_COMPAT_PHP5_H
