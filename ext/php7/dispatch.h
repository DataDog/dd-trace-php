#ifndef DISPATCH_H
#define DISPATCH_H

#include <Zend/zend_types.h>
#include <php.h>
#include <stdbool.h>
#include <stdint.h>

#include "compatibility.h"
#include "ddtrace_string.h"

/* We use the two lowest bits as an index into an array to cut down on
 * conditional logic.
 * First bit: pre or post hook
 * Second bit: tracing or non tracing
 * Since a tracing posthook is most common, it should be 00.
 */
#define DDTRACE_DISPATCH_POSTHOOK 0u
#define DDTRACE_DISPATCH_PREHOOK (1u)
#define DDTRACE_DISPATCH_NON_TRACING (1u << 1u)
#define DDTRACE_DISPATCH_DEFERRED_LOADER (1u << 3u)
#define DDTRACE_DISPATCH_INSTRUMENT_WHEN_LIMITED (1u << 4u)

/* This grabs the 2 least significant bits; used to index into an array at run-
 * time to reduce conditional logic and keep the code clean.
 */
#define DDTRACE_DISPATCH_JUMP_OFFSET(options) ((options)&UINT16_C(3))

typedef struct ddtrace_dispatch_t {
    uint16_t options;
    bool busy;
    uint32_t acquired;
    union {
        zval callable;  // legacy
        zval deferred_load_integration_name;
        zval prehook;
        zval posthook;
    };
    zval function_name;
} ddtrace_dispatch_t;

bool ddtrace_try_find_dispatch(zend_class_entry *scope, zval *fname, ddtrace_dispatch_t **dispatch_ptr,
                               HashTable **function_table);
ddtrace_dispatch_t *ddtrace_find_dispatch(zend_class_entry *scope, zval *fname);
zend_bool ddtrace_trace(zval *class_name, zval *function_name, zval *callable, uint32_t options);
zend_bool ddtrace_hook_callable(ddtrace_string class_name, ddtrace_string function_name, ddtrace_string callable,
                                uint32_t options);

void ddtrace_dispatch_dtor(ddtrace_dispatch_t *dispatch);

inline void ddtrace_dispatch_copy(ddtrace_dispatch_t *dispatch) { dispatch->busy = ++dispatch->acquired > 1; }

inline void ddtrace_dispatch_release(ddtrace_dispatch_t *dispatch) {
    uint32_t acquired = --dispatch->acquired;
    if (acquired == 0) {
        ddtrace_dispatch_dtor(dispatch);
        efree(dispatch);
    } else {
        dispatch->busy = acquired > 1;
    }
}

void ddtrace_dispatch_init(void);
void ddtrace_dispatch_destroy(void);
void ddtrace_dispatch_reset(void);

void ddtrace_class_lookup_release_compat(zval *zv);

#define INIT_ZVAL(x) ZVAL_NULL(&x)

HashTable *ddtrace_new_class_lookup(zval *clazz);
zend_bool ddtrace_dispatch_store(HashTable *class_lookup, ddtrace_dispatch_t *dispatch);

#endif  // DISPATCH_H
