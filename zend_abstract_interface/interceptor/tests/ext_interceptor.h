#ifndef EXT_INTERCEPTOR_H
#define EXT_INTERCEPTOR_H

#include "zai_sapi/zai_sapi.h"

// Injected into the test extension's MINIT function to install targets
extern void (*ext_interceptor_targets)(void);

#define EXT_HOOK_TYPE_STATIC  0
#define EXT_HOOK_TYPE_DYNAMIC 1

typedef struct internal_hooks_s {
    uint16_t type; /* MUST be the first member of this struct */
    void (*prehook)(zend_execute_data *execute_data);
    void (*posthook)(zend_execute_data *execute_data, zval *retval);
} internal_hooks;

typedef struct runtime_hooks_s {
    internal_hooks internal; /* MUST be the first member of this struct ('type' is at the top) */
    struct {
        zval prehook;
        zval posthook;
    } userland;
} runtime_hooks;

uint32_t ext_interceptor_userland_hook_sum(void);

void ext_interceptor_ctor(zend_module_entry *module);

#endif  // EXT_INTERCEPTOR_H
