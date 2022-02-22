#include "symbols.h"

#include <ctype.h>
#include <sandbox/sandbox.h>

#include <Zend/zend_closures.h>

/*
 * This interface is focused on making calls as an observer, it tries to avoid visible side effects
 *
 * Calls are executed with functions, runtime caches, and arguments allocated with a single emalloc
 *
 * When a user function is called that has not yet been initialized it is the callers
 * responsibility to initialize the function. Which means allocating a run time cache, sometimes copying the function.
 *
 * Some versions of PHP export some API, which has visible side effecfs, such as allocating on the compilers
 * arena, or setting map pointers, or otherwise mutating the runtime.
 *
 * Trying to decide how to use the API, on which versions of PHP, leads to difficult to read and understand code
 *
 * The requirements of a call are simple to model, and in taking care of every detail, consistently across versions
 * of PHP, we produce an interface that is much easier to debug, and achieves the goal of avoiding visible side effects
 *
 */

/* {{{ private arena code */
typedef struct {
    char *base;
    char *ptr;
    size_t used;
    size_t size;
} zai_symbol_call_arena_t;

static inline void zai_symbol_call_arena_create(zai_symbol_call_arena_t *arena, zend_fcall_info_cache *fcc,
                                                uint32_t argc) {
    zend_function *fbc = fcc->function_handler;

    size_t zai_symbol_call_arena_size = 0;

    if (fbc->type == ZEND_USER_FUNCTION) {
        zai_symbol_call_arena_size += ZEND_MM_ALIGNED_SIZE(sizeof(zend_op_array));

#if PHP_VERSION_ID >= 70000
        zai_symbol_call_arena_size += ZEND_MM_ALIGNED_SIZE(fbc->op_array.cache_size);
#elif PHP_VERSION_ID >= 50400
        zai_symbol_call_arena_size += ZEND_MM_ALIGNED_SIZE(fbc->op_array.last_cache_slot * sizeof(void *));
#endif

#if PHP_VERSION_ID >= 70400
        zai_symbol_call_arena_size += ZEND_MM_ALIGNED_SIZE(sizeof(void *));
#endif
    } else {
        if (fbc->common.scope != fcc->called_scope) {
            zai_symbol_call_arena_size += ZEND_MM_ALIGNED_SIZE(sizeof(zend_internal_function));
        }
    }

    if (argc) {
#if PHP_VERSION_ID >= 70000
        zai_symbol_call_arena_size += ZEND_MM_ALIGNED_SIZE(sizeof(zval) * argc);
#else
        zai_symbol_call_arena_size += ZEND_MM_ALIGNED_SIZE(sizeof(zval **) * argc);
#endif
    }

    if (!zai_symbol_call_arena_size) {
        memset(arena, 0, sizeof(zai_symbol_call_arena_t));
        return;
    }

    arena->base = emalloc(zai_symbol_call_arena_size);

    memset(arena->base, 0, zai_symbol_call_arena_size);

    arena->ptr = arena->base;
    arena->size = zai_symbol_call_arena_size;
    arena->used = 0;
}

static inline void *zai_symbol_call_arena_alloc(zai_symbol_call_arena_t *arena, size_t size) {
    size = ZEND_MM_ALIGNED_SIZE(size);

    if ((arena->used + size) > arena->size) {
        return NULL;
    }

    char *ptr = arena->ptr;

    arena->ptr = ptr + size;
    arena->used += size;

    return ptr;
}

static inline void zai_symbol_call_arena_release(zai_symbol_call_arena_t *arena) {
    if (!arena->size) {
        return;
    }

    efree(arena->base);
} /* }}} */

/* {{{ private call code */
static inline void zai_symbol_call_argv(zai_symbol_call_arena_t *arena, zend_fcall_info *fci, uint32_t argc,
                                        va_list *args) {
#if PHP_VERSION_ID >= 70000
    size_t zai_symbol_call_argv_size = sizeof(zval) * argc;
#else
    size_t zai_symbol_call_argv_size = sizeof(zval **) * argc;
#endif

    fci->params = zai_symbol_call_arena_alloc(arena, zai_symbol_call_argv_size);

    for (uint32_t arg = 0; arg < argc; arg++) {
        zval **param = va_arg(*args, zval **);

#if PHP_VERSION_ID >= 70000
        ZVAL_COPY_VALUE(&fci->params[arg], *param);
#else
        fci->params[arg] = param;
#endif
    }
    fci->param_count = argc;
}

static inline zend_function *zai_symbol_call_init_internal(zai_symbol_call_arena_t *arena,
                                                           zend_internal_function *function, zend_class_entry *scope) {
    /* we only need to copy internal functions if we are mutating */
    if (function->scope == scope) {
        return (zend_function *)function;
    }

    zend_internal_function *allocated = zai_symbol_call_arena_alloc(arena, sizeof(zend_internal_function));

    memcpy(allocated, function, sizeof(zend_internal_function));

    /* should this function ever find it's way to an
        INIT, it would be terrible if it were cached */
    allocated->fn_flags |= ZEND_ACC_NEVER_CACHE;

    /* scope this copied function */
    allocated->scope = scope;

    return (zend_function *)allocated;
}

static inline zend_function *zai_symbol_call_init_user(zai_symbol_call_arena_t *arena, zend_op_array *ops,
                                                       zend_class_entry *scope) {
    size_t zai_symbol_call_init_size = sizeof(zend_op_array);

#if PHP_VERSION_ID >= 70000
    zai_symbol_call_init_size += ops->cache_size;
#elif PHP_VERSION_ID >= 50400
    zai_symbol_call_init_size += ops->last_cache_slot * sizeof(void *);
#endif

#if PHP_VERSION_ID >= 70400
    zai_symbol_call_init_size += sizeof(void *);
#endif

    zend_op_array *allocated = zai_symbol_call_arena_alloc(arena, zai_symbol_call_init_size);

    memcpy(allocated, ops, sizeof(zend_op_array));

#if PHP_VERSION_ID >= 50400
    /* get run time cache address */
    void *rtc = (void **)(allocated + 1);

#if PHP_VERSION_ID >= 70400
    /* initialize cache address ptr, uses sizeof(void*) */
    ZEND_MAP_PTR_INIT(allocated->run_time_cache, rtc);

    /* get actual runtime cache address, after map pointer */
    rtc = (char *)rtc + sizeof(void *);

    /* map cache address */
    ZEND_MAP_PTR_SET(allocated->run_time_cache, rtc);

    /* the cache is on the heap, however, we are going to free it */
    allocated->fn_flags &= ~ZEND_ACC_HEAP_RT_CACHE;
#else
    /* set cache address */
    allocated->run_time_cache = rtc;
#endif /* if PHP_VERSION_ID >= 70400 */

    /* zero run time cache */
#if PHP_VERSION_ID >= 70000
    memset(rtc, 0, allocated->cache_size);
#else
    memset(rtc, 0, allocated->last_cache_slot * sizeof(void *));
#endif /* if PHP_VERSION_ID >= 70000 */

#endif /* if PHP_VERSION_ID >= 50400 */

#if PHP_VERSION_ID >= 70300
    allocated->fn_flags &= ~ZEND_ACC_IMMUTABLE;
#endif

    /* no longer a real closure object */
    allocated->fn_flags &= ~ZEND_ACC_CLOSURE;

    /* should this function ever find it's way to an
        INIT, it would be terrible if it were cached */
    allocated->fn_flags |= ZEND_ACC_NEVER_CACHE;

    /* scope this copied function */
    allocated->scope = scope;

    return (zend_function *)allocated;
}

static inline zend_function *zai_symbol_call_init(zai_symbol_call_arena_t *arena, zend_fcall_info_cache *fcc) {
    zend_function *fbc = fcc->function_handler;

    if (fbc->type == ZEND_USER_FUNCTION) {
        return zai_symbol_call_init_user(arena, (zend_op_array *)fbc, fcc->called_scope);
    }
    return zai_symbol_call_init_internal(arena, (zend_internal_function *)fbc, fcc->called_scope);
} /* }}} */

bool zai_symbol_call_impl(
    // clang-format off
    zai_symbol_scope_t scope_type, void *scope,
    zai_symbol_function_t function_type, void *function,
    zval **rv ZAI_TSRMLS_DC,
    uint32_t argc, va_list *args
    // clang-format on
) {
    zend_fcall_info fci = empty_fcall_info;
    zend_fcall_info_cache fcc = empty_fcall_info_cache;

    fci.size = sizeof(zend_fcall_info);
#if PHP_VERSION_ID >= 70000
    fci.retval = *rv;
#else
    fci.retval_ptr_ptr = rv;
#endif

#if PHP_VERSION_ID < 70300
    fcc.initialized = 1;
#endif

    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_CLASS:
            fcc.called_scope = (zend_class_entry *)scope;
            break;

        case ZAI_SYMBOL_SCOPE_OBJECT: {
            fcc.called_scope = Z_OBJCE_P((zval *)scope);
#if PHP_VERSION_ID >= 70000
            fci.object = fcc.object = Z_OBJ_P((zval *)scope);
#else
            fci.object_ptr = fcc.object_ptr = (zval *)scope;
#endif
        } break;

        case ZAI_SYMBOL_SCOPE_GLOBAL:
        case ZAI_SYMBOL_SCOPE_NAMESPACE:
            /* nothing to do */
            break;

        default:
            assert(0 && "call may not be performed in frame and static scopes");
            return NULL;
    }

    // clang-format off
    switch (function_type) {
        case ZAI_SYMBOL_FUNCTION_KNOWN:
            fcc.function_handler = (zend_function *)function;
            break;

        case ZAI_SYMBOL_FUNCTION_NAMED:
            fcc.function_handler =
                zai_symbol_lookup(
                    ZAI_SYMBOL_TYPE_FUNCTION,
                    scope_type, scope,
                    function ZAI_TSRMLS_CC);
            break;

        case ZAI_SYMBOL_FUNCTION_CLOSURE:
#if PHP_VERSION_ID >= 80000
            fcc.function_handler = (zend_function *)zend_get_closure_method_def(Z_OBJ_P((zval *)function));
#else
            fcc.function_handler = (zend_function *)zend_get_closure_method_def((zval *)function ZAI_TSRMLS_CC);
#endif
            if ((scope_type != ZAI_SYMBOL_SCOPE_CLASS) &&
                (scope_type != ZAI_SYMBOL_SCOPE_OBJECT)) {
                zval *object = zend_get_closure_this_ptr((zval*) function ZAI_TSRMLS_CC);

                if (object && Z_TYPE_P(object) == IS_OBJECT) {
                    fcc.called_scope = Z_OBJCE_P(object);
#if PHP_VERSION_ID >= 70000
                    fci.object = fcc.object = Z_OBJ_P(object);
#else
                    fci.object_ptr = fcc.object_ptr = object;
#endif
                }
            }
            break;
    }
    // clang-format on

    if (!fcc.function_handler) {
        return false;
    }

    if (fcc.function_handler->common.fn_flags & ZEND_ACC_ABSTRACT) {
        return false;
    }

    if (scope_type == ZAI_SYMBOL_SCOPE_CLASS) {
        if (!(fcc.function_handler->common.fn_flags & ZEND_ACC_STATIC)) {
            return false;
        }
    }

    // clang-format off
    volatile int  zai_symbol_call_result    = FAILURE;
    volatile bool zai_symbol_call_exception = false;
    volatile bool zai_symbol_call_bailed    = false;

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    zai_symbol_call_arena_t arena;
    zend_try {
        zai_symbol_call_arena_create(&arena, &fcc, argc);
    } zend_catch {
        zai_symbol_call_bailed = true;
    } zend_end_try();

    if (zai_symbol_call_bailed) {
        zai_sandbox_bailout(&sandbox);
        zai_sandbox_close(&sandbox);
        return false;
    }

    fcc.function_handler = zai_symbol_call_init(&arena, &fcc);

    if (argc) {
        zai_symbol_call_argv(&arena, &fci, argc, args);
    }

    zend_try {
        zai_symbol_call_result =
            zend_call_function(&fci, &fcc ZAI_TSRMLS_CC);
    } zend_catch {
        zai_symbol_call_bailed = true;
    } zend_end_try();
    // clang-format on

    zai_symbol_call_arena_release(&arena);

    if (zai_symbol_call_bailed) {
        zai_sandbox_bailout(&sandbox);
    }

    zai_symbol_call_exception = EG(exception) != NULL;

    zai_sandbox_close(&sandbox);

    if (zai_symbol_call_result == SUCCESS) {
        return !zai_symbol_call_exception;
    }

    return false;
}

bool zai_symbol_new(zval *zv, zend_class_entry *ce ZAI_TSRMLS_DC, uint32_t argc, ...) {
    bool result = true;

    object_init_ex(zv, ce);

    if (ce->constructor) {
        va_list args;
        va_start(args, argc);

        zval rz, *rv = &rz;
        // clang-format off
        result = zai_symbol_call_impl(
            ZAI_SYMBOL_SCOPE_OBJECT, zv,
            ZAI_SYMBOL_FUNCTION_KNOWN, ce->constructor,
            &rv ZAI_TSRMLS_CC,
            argc, &args);
        // clang-format on

#if PHP_VERSION_ID >= 70000
        zval_ptr_dtor(rv);
#else
        zval_ptr_dtor(&rv);
#endif

        va_end(args);
    }

    return result;
}
