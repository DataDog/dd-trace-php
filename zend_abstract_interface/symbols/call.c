#include "../tsrmls_cache.h"
#include "symbols.h"

#if PHP_VERSION_ID >= 80000
#include <Zend/zend_observer.h>
#endif
#if PHP_VERSION_ID >= 80200
#include <Zend/zend_extensions.h>
#endif

#include <Zend/zend_closures.h>
#include <ctype.h>
#include <sandbox/sandbox.h>

#if PHP_VERSION_ID <= 70200
#define ZEND_ACC_FAKE_CLOSURE ZEND_ACC_INTERFACE
#endif

#if PHP_VERSION_ID >= 80000
// stack allocate some memory to avoid overwriting stack allocated things needed for observers
char (*zai_call_throwaway_buffer_pointer)[];
zend_result zend_call_function_wrapper(zend_fcall_info *fci, zend_fcall_info_cache *fci_cache) {
#ifdef __SANITIZE_ADDRESS__
#define STACK_BUFFER_SIZE 8192 // asan has more overhead
#else
#define STACK_BUFFER_SIZE 6144
#endif
    char buffer[STACK_BUFFER_SIZE];  // dynamic runtime symbol resolving can have some stack overhead
    zai_call_throwaway_buffer_pointer = &buffer;
    return zend_call_function(fci, fci_cache);
}

#define zend_call_function zend_call_function_wrapper
#endif

#if PHP_VERSION_ID >= 80200
#define ZEND_OBSERVER_NOT_OBSERVED ((void *) 2)

zend_execute_data *zai_set_observed_frame(zend_execute_data *execute_data) {
    // Although the tracer being present should always cause an observer to be
    // present, if zai is used from another extension, like say the profiler,
    // then this may not be set.
    if (zend_observer_fcall_op_array_extension < 0) {
        return NULL;
    }

    zend_execute_data fake_ex[2]; // 2 to have some space for observer temps
    zend_function dummy_observable_func;
    dummy_observable_func.type = ZEND_INTERNAL_FUNCTION;
    dummy_observable_func.common.fn_flags = 0;
    dummy_observable_func.common.T = 1; // the single temporary having the prev_observed address
    fake_ex->func = &dummy_observable_func;
    fake_ex->prev_execute_data = execute_data;
    ZEND_CALL_NUM_ARGS(fake_ex) = 0;

    size_t cache_size = zend_internal_run_time_cache_reserved_size();
    void **rt_cache = ecalloc(cache_size, 1);
    // Set the begin handler to not observed and the end handler (where ever it is) to NULL (implicitly due to ecalloc)
#if PHP_VERSION_ID >= 80400
    rt_cache[zend_observer_fcall_internal_function_extension] = ZEND_OBSERVER_NOT_OBSERVED;
#else
    rt_cache[zend_observer_fcall_op_array_extension] = ZEND_OBSERVER_NOT_OBSERVED;
#endif
    ZEND_MAP_PTR_INIT(dummy_observable_func.op_array.run_time_cache, rt_cache);

    // We have a run_time cache with nothing observed, meaning no uncontrolled code will be executed now
    // However, it will in any case update current_observed_frame to our fake frame (needed so that zend_observer_fcall_end() accepts our fake frame)
    zend_observer_fcall_begin(fake_ex);

    // write the prev_observed address
    zend_execute_data **prev_observed = (zend_execute_data **)&fake_ex[1], *cur_prev_observed = *prev_observed;
    *prev_observed = execute_data;

    // Now, fetch current_observed_frame from the prev_observed address of the fake frame
    zend_observer_fcall_end(fake_ex, NULL);

    efree(rt_cache);

    return cur_prev_observed;
}
#endif

#if PHP_VERSION_ID >= 80000
void zai_reset_observed_frame_post_bailout(void) {
    if (EG(current_execute_data)) {
        zend_execute_data *cur_ex = EG(current_execute_data);
        zend_execute_data backup_ex = *cur_ex;
        EG(current_execute_data) = &backup_ex;
        cur_ex->prev_execute_data = NULL;
        cur_ex->func = NULL;
        zend_observer_fcall_end_all();
        *cur_ex = *EG(current_execute_data);
        EG(current_execute_data) = cur_ex;
    } else {
        zend_observer_fcall_end_all();
    }
}
#endif

static inline int zai_symbol_try_call(zend_fcall_info *fci, zend_fcall_info_cache *fcc) {
    volatile int ret;
    zend_try {
        ret = zend_call_function(fci, fcc);
    } zend_catch {
        ret = 2;
        if (PG(last_error_message) && strstr(ZSTR_VAL(PG(last_error_message)), "Datadog blocked the request")) {
            zend_bailout();
        }
    } zend_end_try();
    return ret;
}

/* {{{ private call code */
bool zai_symbol_call_impl(
    // clang-format off
    zai_symbol_scope_t scope_type, void *scope,
    zai_symbol_function_t function_type, void *function,
    zval *rv,
    uint32_t argc, va_list *args
    // clang-format on
) {
    zend_fcall_info fci = empty_fcall_info;
    zend_fcall_info_cache fcc = empty_fcall_info_cache;

    fci.size = sizeof(zend_fcall_info);
    ZVAL_NULL(rv);
    fci.retval = rv;
    bool success = false;

#if PHP_VERSION_ID < 70300
    fcc.initialized = 1;
#endif

    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_CLASS:
            fcc.called_scope = (zend_class_entry *)scope;
            break;

        case ZAI_SYMBOL_SCOPE_OBJECT: {
            fcc.called_scope = Z_OBJCE_P((zval *)scope);
            fci.object = fcc.object = Z_OBJ_P((zval *)scope);
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
                    function);
            break;

        case ZAI_SYMBOL_FUNCTION_CLOSURE:
#if PHP_VERSION_ID >= 80000
            fcc.function_handler = (zend_function *)zend_get_closure_method_def(Z_OBJ_P((zval *)function));
#else
            fcc.function_handler = (zend_function *)zend_get_closure_method_def((zval *)function	);
#endif
            if ((scope_type != ZAI_SYMBOL_SCOPE_CLASS) &&
                (scope_type != ZAI_SYMBOL_SCOPE_OBJECT)) {
                zval *object = zend_get_closure_this_ptr((zval*) function);

                if (object && Z_TYPE_P(object) == IS_OBJECT) {
                    fcc.called_scope = Z_OBJCE_P(object);
                    fci.object = fcc.object = Z_OBJ_P(object);
                } else {
                    fcc.called_scope = fcc.function_handler->common.scope;
                }
            }
            break;
    }
    // clang-format on

    // Always open the sandbox, because the caller will always try to close it
    zai_sandbox sandbox, *sandbox_ptr = &sandbox;
    volatile bool needs_sandbox = (argc & ZAI_SYMBOL_SANDBOX) == 0;
    if (needs_sandbox) {
        zai_sandbox_open(&sandbox);
    } else {
        sandbox_ptr = va_arg(*args, zai_sandbox *);
    }
    argc &= ~ZAI_SYMBOL_SANDBOX;

    if (!fcc.function_handler) {
        goto leave;
    }

    if (fcc.function_handler->common.fn_flags & ZEND_ACC_ABSTRACT) {
        goto leave;
    }

    if (scope_type == ZAI_SYMBOL_SCOPE_CLASS) {
        if (!(fcc.function_handler->common.fn_flags & (ZEND_ACC_STATIC | ZEND_ACC_CLOSURE))) {
            goto leave;
        }
    }

    // clang-format off
    int  zai_symbol_call_result    = FAILURE;
    bool zai_symbol_call_bailed    = false;
    bool rebound_closure = false;
    zval new_closure;
    zend_op_array *op_array = NULL;

    if (function_type == ZAI_SYMBOL_FUNCTION_CLOSURE && fcc.called_scope) {
        zend_class_entry *closure_called_scope;
        zend_function *closure_func;
        zend_object *closure_this;
#if PHP_VERSION_ID < 80000
        Z_OBJ_HANDLER_P((zval *) function, get_closure)((zval *)function, &closure_called_scope, &closure_func, &closure_this);
#else
        Z_OBJ_HANDLER_P((zval *) function, get_closure)(Z_OBJ_P((zval *)function), &closure_called_scope, &closure_func, &closure_this, true);
#endif

        bool is_fake_closure = (fcc.function_handler->common.fn_flags & ZEND_ACC_FAKE_CLOSURE) != 0;
        if (!is_fake_closure)
        {
            rebound_closure = true;

            if (fcc.function_handler->common.fn_flags & ZEND_ACC_GENERATOR) {
                zval target_object_zv, *target_object = &target_object_zv;
                if (scope_type == ZAI_SYMBOL_SCOPE_OBJECT) {
                    target_object = (zval *) scope;
                } else {
                    ZVAL_OBJ(target_object, fcc.object);
                }

                zend_create_closure((zval *) &new_closure, closure_func, fcc.called_scope, closure_called_scope, target_object);

#if PHP_VERSION_ID >= 80000
                fcc.function_handler = (zend_function *)zend_get_closure_method_def(Z_OBJ(new_closure));
#else
                fcc.function_handler = (zend_function *)zend_get_closure_method_def((zval *)&new_closure	);
#endif
            } else {
                op_array = emalloc(sizeof(zend_op_array));
                memcpy(op_array, closure_func, sizeof(zend_op_array));
                op_array->scope = fcc.called_scope;
                op_array->fn_flags &= ~ZEND_ACC_CLOSURE;
#if PHP_VERSION_ID >= 70400
                op_array->fn_flags |= ZEND_ACC_HEAP_RT_CACHE;
#if PHP_VERSION_ID >= 80200
                void *ptr = emalloc((size_t)op_array->cache_size);
                ZEND_MAP_PTR_INIT(op_array->run_time_cache, ptr);
#else
                void *ptr = emalloc(op_array->cache_size + sizeof(void *));
                ZEND_MAP_PTR_INIT(op_array->run_time_cache, ptr);
                ptr = (char*)ptr + sizeof(void*);
                ZEND_MAP_PTR_SET(op_array->run_time_cache, ptr);
#endif
                memset(ptr, 0, op_array->cache_size);
#else
                op_array->run_time_cache = ecalloc(1, op_array->cache_size);
#endif

                fcc.function_handler = (zend_function *)op_array;
            }
        }
    }

    if (argc) {
        size_t zai_symbol_call_argv_size = sizeof(zval) * argc;

        fci.params = emalloc(zai_symbol_call_argv_size);

        for (uint32_t arg = 0; arg < argc; arg++) {
            zval *param = va_arg(*args, zval *);

            // zend_call_function may change fci.params by replacing some
            // parameters with references. To detect this and later free the
            // references, we need to increase the refcount here and call
            // zval_ptr_dtor() for each parameter after the call.
            ZVAL_COPY(&fci.params[arg], param);
        }
        fci.param_count = argc;
    }

#if PHP_VERSION_ID >= 80200
    zend_execute_data *prev_observed = zai_set_observed_frame(NULL);
#endif

    zai_symbol_call_result = zai_symbol_try_call(&fci, &fcc);
    zai_symbol_call_bailed = zai_symbol_call_result == 2;
    // clang-format on

    for (uint32_t arg = 0; arg < argc; arg++) {
        zval_ptr_dtor(&fci.params[arg]);
    }

    if (zai_symbol_call_bailed) {
        zai_sandbox_bailout(sandbox_ptr);
#if PHP_VERSION_ID >= 80000
        zai_reset_observed_frame_post_bailout();
#endif
    }

#if PHP_VERSION_ID >= 80200
    zai_set_observed_frame(prev_observed);
#endif

    if (!zai_symbol_call_bailed && rebound_closure) {
        // We intentially skip freeing upon bailout to avoid crashes in bailout/observer cleanup
        if (fcc.function_handler->common.fn_flags & ZEND_ACC_GENERATOR) {
            /* copied upon generator creation */
            zval_ptr_dtor((zval *)&new_closure);
        } else {
#if PHP_VERSION_ID >= 70400
            efree(ZEND_MAP_PTR(op_array->run_time_cache));
#else
            efree(op_array->run_time_cache);
#endif
            efree(op_array);
        }
    }

    if (fci.param_count) {
        efree(fci.params);
    }

    success = zai_symbol_call_result == SUCCESS && EG(exception) == NULL;

leave:
    if (needs_sandbox) {
        zai_sandbox_close(&sandbox);
    }

    return success;
}

bool zai_symbol_new(zval *zv, zend_class_entry *ce, uint32_t argc, ...) {
    bool result = true;

    object_init_ex(zv, ce);

    if (ce->constructor) {
        va_list args;
        va_start(args, argc);

        zval rv;
        // clang-format off
        result = zai_symbol_call_impl(
            ZAI_SYMBOL_SCOPE_OBJECT, zv,
            ZAI_SYMBOL_FUNCTION_KNOWN, ce->constructor,
            &rv, argc, &args);
        // clang-format on

        zval_ptr_dtor(&rv);

        va_end(args);
    }

    return result;
}
