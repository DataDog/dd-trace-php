extern "C" {
#include "ext_interceptor.h"

#include "interceptor/interceptor.h"
#include "zai_sapi/zai_sapi.h"
}

void (*ext_interceptor_targets)(void) = NULL;

ZEND_TLS uint32_t userland_hook_sum;

uint32_t ext_interceptor_userland_hook_sum(void) {
    return userland_hook_sum;
}

static void static_begin_handler(zai_interceptor_caller_owned *ptr, zend_execute_data *execute_data) {
    internal_hooks *internal = (internal_hooks *)ptr;
    if (internal && internal->prehook) {
        internal->prehook(execute_data);
    }
}

static void static_end_handler(zai_interceptor_caller_owned *ptr, zend_execute_data *execute_data, zval *retval) {
    internal_hooks *internal = (internal_hooks *)ptr;
    if (internal && internal->posthook) {
        internal->posthook(execute_data, retval);
    }
}

static void dynamic_begin_handler(zai_interceptor_caller_owned *ptr, zend_execute_data *execute_data) {
    runtime_hooks *runtime = (runtime_hooks *)ptr;
    if (runtime) {
        static_begin_handler((zai_interceptor_caller_owned *)&runtime->internal, execute_data);
        if (Z_TYPE_INFO(runtime->userland.prehook) == IS_LONG) {
            userland_hook_sum += Z_LVAL(runtime->userland.prehook);
        }
    }
}

static void dynamic_end_handler(zai_interceptor_caller_owned *ptr, zend_execute_data *execute_data, zval *retval) {
    runtime_hooks *runtime = (runtime_hooks *)ptr;
    if (runtime) {
        static_end_handler((zai_interceptor_caller_owned *)&runtime->internal, execute_data, retval);
        if (Z_TYPE_INFO(runtime->userland.posthook) == IS_LONG) {
            userland_hook_sum += Z_LVAL(runtime->userland.posthook);
        }
    }
}

static zai_interceptor_caller_owned *dynamic_ptr_ctor(zai_interceptor_caller_owned *orig_ptr) {
    runtime_hooks *runtime = (runtime_hooks *)ecalloc(1, sizeof(runtime_hooks));
    runtime->internal.type = EXT_HOOK_TYPE_DYNAMIC;
    ZVAL_UNDEF(&runtime->userland.prehook);
    ZVAL_UNDEF(&runtime->userland.posthook);

    if (orig_ptr) {
        internal_hooks *orig_internal = (internal_hooks *)orig_ptr;
        if (orig_internal->type == EXT_HOOK_TYPE_STATIC) {
            runtime->internal.prehook = orig_internal->prehook;
            runtime->internal.posthook = orig_internal->posthook;
        } else {
            runtime_hooks *orig_runtime = (runtime_hooks *)orig_ptr;
            runtime->internal.prehook = orig_runtime->internal.prehook;
            runtime->internal.posthook = orig_runtime->internal.posthook;
            /* In the real world, here we would need to handle the case where a
             * userland hook is being overwritten at runtime. But for testing we
             * just ignore this case because it does not concern ZAI
             * interceptor.
             */
            if (Z_TYPE_INFO(runtime->userland.prehook) == IS_UNDEF && Z_TYPE_INFO(orig_runtime->userland.prehook) != IS_UNDEF) {
                ZVAL_COPY(&runtime->userland.prehook, &orig_runtime->userland.prehook);
            }
            if (Z_TYPE_INFO(runtime->userland.posthook) == IS_UNDEF && Z_TYPE_INFO(orig_runtime->userland.posthook) != IS_UNDEF) {
                ZVAL_COPY(&runtime->userland.posthook, &orig_runtime->userland.posthook);
            }
        }
    }
    return (zai_interceptor_caller_owned *)runtime;
}

static void dynamic_ptr_dtor(zai_interceptor_caller_owned *ptr) {
    runtime_hooks *runtime = (runtime_hooks *)ptr;
    if (runtime && runtime->internal.type == EXT_HOOK_TYPE_DYNAMIC) {
        zval_ptr_dtor(&runtime->userland.prehook);
        zval_ptr_dtor(&runtime->userland.posthook);
        efree(runtime);
    }
}

static PHP_MINIT_FUNCTION(interceptor) {
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zai_interceptor_minit(
        static_begin_handler, static_end_handler,
        dynamic_begin_handler, dynamic_end_handler,
        dynamic_ptr_ctor, dynamic_ptr_dtor
    );
    assert(ext_interceptor_targets && "Targets installer not set");
    ext_interceptor_targets();
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(interceptor) {
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zai_interceptor_mshutdown();
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

static PHP_RINIT_FUNCTION(interceptor) {
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    userland_hook_sum = 0;
    zai_interceptor_rinit();
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(interceptor) {
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zai_interceptor_rshutdown();
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

void ext_interceptor_ctor(zend_module_entry *module) {
    module->module_startup_func = PHP_MINIT(interceptor);
    module->module_shutdown_func = PHP_MSHUTDOWN(interceptor);
    module->request_startup_func = PHP_RINIT(interceptor);
    module->request_shutdown_func = PHP_RSHUTDOWN(interceptor);
}
