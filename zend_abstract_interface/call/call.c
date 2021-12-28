#include "call.h"

#include <ctype.h>
#include <sandbox/sandbox.h>
#include <zai_assert/zai_assert.h>

// clang-format off
static bool zai_call_impl(
    zai_call_scope_t scope_type, void *scope,
    zai_call_function_t function_type, void *function,
    zval **rv ZAI_TSRMLS_DC,
    uint32_t argc, va_list *args);
// clang-format on

static inline void *zai_call_lookup_table(HashTable *table, const char *name, size_t length ZAI_TSRMLS_DC) {
    void *result = NULL;

#if PHP_VERSION_ID >= 70000
    result = zend_hash_str_find_ptr(table, name, length);
#else
    zend_hash_find(table, name, length + 1, (void **)&result);
#endif

    if (!result) {
        char *ptr = (char *)malloc(length + 1);

        if (!ptr) {
            return NULL;
        }

        for (uint32_t c = 0; c < length; c++) {
            ptr[c] = tolower(name[c]);
        }

        ptr[length] = 0;

#if PHP_VERSION_ID >= 70000
        result = zend_hash_str_find_ptr(table, ptr, length);
#else
        zend_hash_find(table, ptr, length + 1, (void **)&result);
#endif

        free(ptr);
    }

    return result;
}

zend_class_entry *zai_call_lookup_class(zai_call_scope_t scope_type, zai_string_view *scope ZAI_TSRMLS_DC) {
    void *result = NULL;
    zai_string_view key = *scope;

    switch (scope_type) {
        case ZAI_CALL_SCOPE_STATIC:
            /* TODO deal with parent/self/static */
            assert(0);
            break;

        case ZAI_CALL_SCOPE_NAMED:
            if ((key.len > 1) && (memcmp(key.ptr, "\\", sizeof(char)) == SUCCESS)) {
                key.ptr++;
                key.len--;
            }

            result = zai_call_lookup_table(EG(class_table), key.ptr, key.len ZAI_TSRMLS_CC);
            break;

        default:
            assert(0);
    }
#if PHP_VERSION_ID >= 70000
    return result;
#else
    return result ? *(void **)result : NULL;
#endif
}

zend_function *zai_call_lookup_function(zai_call_scope_t scope_type, void *scope, zai_call_function_t function_type,
                                        void *function ZAI_TSRMLS_DC) {
    zend_function *result;
    HashTable *table = NULL;

    switch (scope_type) {
        case ZAI_CALL_SCOPE_STATIC:
            table = &((zend_class_entry *)scope)->function_table;
            break;

        case ZAI_CALL_SCOPE_OBJECT:
            table = &Z_OBJCE_P((zval *)scope)->function_table;
            break;

        case ZAI_CALL_SCOPE_GLOBAL:
            table = EG(function_table);
            break;

        case ZAI_CALL_SCOPE_NAMED: {
            zend_class_entry *ce = zai_call_lookup_class(scope_type, (zai_string_view *)scope ZAI_TSRMLS_CC);

            if (ce) {
                table = &ce->function_table;
            }
        } break;
    }

    if (!table) {
        return NULL;
    }

    switch (function_type) {
        case ZAI_CALL_FUNCTION_KNOWN:
            /* TODO deal with fetching from zend_function* */
            break;

        case ZAI_CALL_FUNCTION_NAMED: {
            zai_string_view *view = (zai_string_view *)function;

            result = zai_call_lookup_table(table, view->ptr, view->len ZAI_TSRMLS_CC);
        } break;
    }

    return result;
}

bool zai_call(zai_call_scope_t scope_type, void *scope, zai_call_function_t function_type, void *function,
              zval **rv ZAI_TSRMLS_DC, uint32_t argc, ...) {
    bool result;

    va_list args;
    va_start(args, argc);
    result = zai_call_impl(scope_type, scope, function_type, function, rv ZAI_TSRMLS_CC, argc, &args);
    va_end(args);

    return result;
}

static inline void zai_call_argv(zend_fcall_info *fci, uint32_t argc, va_list *args ZAI_TSRMLS_DC) {
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

static bool zai_call_impl(
    // clang-format off
    zai_call_scope_t scope_type, void *scope,
    zai_call_function_t function_type, void *function,
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
        case ZAI_CALL_SCOPE_STATIC:
            fcc.called_scope = (zend_class_entry *)scope;
            break;

        case ZAI_CALL_SCOPE_OBJECT: {
            fcc.called_scope = Z_OBJCE_P((zval *)scope);
#if PHP_VERSION_ID >= 70000
            fci.object = fcc.object = Z_OBJ_P((zval *)scope);
#else
            fci.object_ptr = fcc.object_ptr = (zval *)scope;
#endif
        } break;

        case ZAI_CALL_SCOPE_GLOBAL:
            /* nothing to do */
            break;
    }

    switch (function_type) {
        case ZAI_CALL_FUNCTION_KNOWN:
            fcc.function_handler = (zend_function *)function;
            break;

        case ZAI_CALL_FUNCTION_NAMED:
            // clang-format off
            fcc.function_handler =
                zai_call_lookup_function(
                    scope_type, scope,
                    function_type, function ZAI_TSRMLS_CC);
            // clang-format on
            break;
    }

    if (!fcc.function_handler) {
        return false;
    }

    if (scope_type == ZAI_CALL_SCOPE_STATIC) {
        if (!(fcc.function_handler->common.fn_flags & ZEND_ACC_STATIC)) {
            return false;
        }
    }

    if (fcc.function_handler->common.fn_flags & ZEND_ACC_ABSTRACT) {
        return false;
    }

    volatile bool zai_call_bailed = false;
    volatile int zai_call_result = SUCCESS;

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    if (argc) {
        zai_call_argv(&fci, argc, args ZAI_TSRMLS_CC);
    }

    // clang-format off
    zend_try {
        zai_call_result =
            zend_call_function(&fci, &fcc ZAI_TSRMLS_CC);
    } zend_catch {
        zai_call_bailed = true;
    } zend_end_try();
    // clang-format on

    if (argc) {
        efree(fci.params);
    }

    if (zai_call_bailed) {
        zai_sandbox_close(&sandbox);
        zend_bailout();
    }

    if ((zai_call_result == SUCCESS) && (NULL == EG(exception))) {
        zai_sandbox_close(&sandbox);
        return true;
    }

    zai_sandbox_close(&sandbox);
    return false;
}

bool zai_call_new(zval *zv, zend_class_entry *ce ZAI_TSRMLS_DC, uint32_t argc, ...) {
    bool result = true;

    memset(zv, 0, sizeof(zv));

    object_init_ex(zv, ce);

    if (ce->constructor) {
        va_list args;
        va_start(args, argc);

        zval rz, *rv = &rz;
        // clang-format off
        result = zai_call_impl(
            ZAI_CALL_SCOPE_OBJECT, zv,
            ZAI_CALL_FUNCTION_KNOWN, ce->constructor,
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
