#include "symbols.h"

#include <ctype.h>
#include <sandbox/sandbox.h>
#include <zai_assert/zai_assert.h>

static inline void zai_symbol_call_argv(zend_fcall_info *fci, uint32_t argc, va_list *args ZAI_TSRMLS_DC) {
#if PHP_VERSION_ID < 70000
    fci->params = emalloc(argc * sizeof(zval **));
#else
    fci->params = emalloc(argc * sizeof(zval));
#endif
    for (uint32_t arg = 0; arg < argc; arg++) {
        zval **param = va_arg(*args, zval **);
#if PHP_VERSION_ID < 70000
        fci->params[arg] = param;
#else
        ZVAL_COPY_VALUE(&fci->params[arg], *param);
#endif
    }
    fci->param_count = argc;
}

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
#if PHP_VERSION_ID < 70300
    fcc.initialized = 1;
#endif
#else
    fcc.initialized = 1;
    fci.retval_ptr_ptr = rv;
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
            /* nothing to do */
            break;

        case ZAI_SYMBOL_SCOPE_NAMESPACE:
            /* nothing to do yet */
            break;
    }

    switch (function_type) {
        case ZAI_SYMBOL_FUNCTION_KNOWN:
            fcc.function_handler = (zend_function *)function;
            break;

        case ZAI_SYMBOL_FUNCTION_NAMED:
            // clang-format off
            fcc.function_handler =
                zai_symbol_lookup(
                    ZAI_SYMBOL_TYPE_FUNCTION,
                    scope_type, scope,
                    function ZAI_TSRMLS_CC);
            // clang-format on
            break;
    }

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

    volatile int zai_symbol_call_result = FAILURE;

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    if (argc) {
        zai_symbol_call_argv(&fci, argc, args ZAI_TSRMLS_CC);
    }

    // clang-format off
    zend_try {
        zai_symbol_call_result =
            zend_call_function(&fci, &fcc ZAI_TSRMLS_CC);
    } zend_end_try();
    // clang-format on

    if (argc) {
        efree(fci.params);
    }

    if ((zai_symbol_call_result == SUCCESS) && (NULL == EG(exception))) {
        zai_sandbox_close(&sandbox);
        return true;
    }

    zai_sandbox_close(&sandbox);
    return false;
}

bool zai_symbol_new(zval *zv, zend_class_entry *ce ZAI_TSRMLS_DC, uint32_t argc, ...) {
    bool result = true;

    memset(zv, 0, sizeof(zv));

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

#if PHP_VERSION_ID < 70000
        zval_ptr_dtor(&rv);
#else
        zval_ptr_dtor(rv);
#endif
        va_end(args);
    }

    return result;
}
