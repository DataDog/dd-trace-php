#ifndef ZAI_JIT_BLACKLIST_H
#define ZAI_JIT_BLACKLIST_H

#include <main/php_version.h>
#include <Zend/zend_compile.h>

#if __x86_64__ || defined(_WIN64)
#define ZAI_JIT_BLACKLIST_ACTIVE PHP_VERSION_ID >= 80000
#elif defined(__aarch64__)
#define ZAI_JIT_BLACKLIST_ACTIVE PHP_VERSION_ID >= 80100
#else
#define ZAI_JIT_BLACKLIST_ACTIVE 0
#endif

/* Delivery limitations reported to arm callers. The result type is also needed on builds without JIT. */
typedef enum {
    ZAI_JIT_BLACKLIST_APPLIED = 0,         /* no bypass reported; unavailable metadata also takes this fallback */
    ZAI_JIT_BLACKLIST_ALREADY_COMPILED,    /* existing compiled code can bypass the handler */
    ZAI_JIT_BLACKLIST_UNSUPPORTED_TRIGGER, /* a remaining JIT trigger may compile the function later */
} zai_jit_blacklist_result;

#if ZAI_JIT_BLACKLIST_ACTIVE
int zai_get_zend_func_rid(zend_op_array *op_array);

/* Attempt to disable further JIT compilation.
   Non-APPLIED results identify known bypasses.
   APPLIED also covers unavailable metadata/symbols or a failed protection change, so it does not guarantee delivery. */
zai_jit_blacklist_result zai_jit_blacklist_function_inlining(zend_op_array *op_array);
#endif

#endif // ZAI_JIT_BLACKLIST_H
