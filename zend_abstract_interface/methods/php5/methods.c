#include "../methods.h"

#include <Zend/zend_interfaces.h>
#include <sandbox/sandbox.h>
#include <stdint.h>
#include <zai_assert/zai_assert.h>

#define MAX_ARGS 3

zend_class_entry *zai_class_lookup_ex(const char *cname, size_t cname_len TSRMLS_DC) {
    if (!cname || !cname_len) return NULL;
    zend_class_entry **ce;

    zai_assert_is_lower(cname, "Class names must be lowercase.");
    assert(*cname != '\\' && "Class names must not have a root scope prefix '\\'.");

    /* Since we do not want to invoke the autoloader and we assume the caller
     * will pass in the lowercased class name, we look up the class entry from
     * the CG(class_table) directly versus calling zend_lookup_class_ex(). This
     * also allows us to make class lookups outside of a request context which
     * is not possible for zend_lookup_class_ex() since it relies on executor
     * global EG(class_table) for the lookup.
     */
    return (zend_hash_find(CG(class_table), cname, (cname_len + 1), (void **)&ce) == SUCCESS) ? *ce : NULL;
}

static bool z_vcall_method_ex(zval *object, zend_class_entry *ce, const char *method, size_t method_len,
                              zval **retval TSRMLS_DC, int argc, va_list argv) {
    assert(argc <= MAX_ARGS && "Increase MAX_ARGS to support more arguments.");

    if (!ce || !method || !method_len || argc < 0 || argc > MAX_ARGS) goto error_exit;

    /* Methods cannot be called outside of a request context.
     * PG(modules_activated) indicates that all of the module RINITs have been
     * called and we are in a request context.
     */
    if (!PG(modules_activated)) goto error_exit;

    /* This one gets me all the time. Trying to save myself 5 minutes for the
     * next time it inevitably happens.
     */
    zai_assert_is_lower(method, "Don't forget to send those method names in all lowercase. ;)");

    /* There is an important ZEND_ACC_ALLOW_STATIC check that occurs within the
     * VM that is circumvented when calling a method outside of a VM context.
     * Bypassing this check can result in an application crash therefore we must
     * look up the function handler and perform the check manually here.
     *
     * https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_vm_def.h#L2639-L2642
     */
    zend_function *func = NULL;
    if (zend_hash_find(&ce->function_table, method, (method_len + 1), (void **)&func) == FAILURE) {
        /* "Couldn't find implementation for method %s" */
        goto error_exit;
    }

    /* Calling a non-static method statically can result in a SIGSEGV because
     * the VM acts as a NULL check on the object before calling the function
     * handler and we are bypassing the VM context. We only want to allow this
     * behavior on a non-static method if the function has been explicitly
     * marked with ZEND_ACC_ALLOW_STATIC signifying that it is safe to do so.
     */
    if (!object && func->common.scope && !(func->common.fn_flags & (ZEND_ACC_STATIC | ZEND_ACC_ALLOW_STATIC))) {
        // "Non-static method %s::%s() cannot be called statically"
        goto error_exit;
    }

    /* The ZEND_ACC_ABSTRACT check still occurs when calling zend_call_method()
     * directly (but not until zend_call_function() is called). Since we
     * already did the work of pulling out the function handler, we might as
     * well fail early and avoid a potential zend_bailout while we can.
     *
     * https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_execute_API.c#L838-L840
     */
    if (func->common.fn_flags & ZEND_ACC_ABSTRACT) {
        // "Cannot call abstract method %s::%s()"
        goto error_exit;
    }

    zend_fcall_info_cache fcc = empty_fcall_info_cache;
    fcc.initialized = 1;
    fcc.function_handler = func;
    fcc.calling_scope = ce;

    /* Late static binding is intentionally not supported here. Since ZAI
     * methods will be used primarily to call userland methods at atypical
     * points in the VM execution, there might be an edge case where a call to
     * 'static::method_name();' might result in the wrong target class.
     */
#define DD_SUPPORT_LSB 0
#if DD_SUPPORT_LSB
    if (!object && (EG(called_scope) && instanceof_function(EG(called_scope), ce TSRMLS_CC))) {
        fcc.called_scope = EG(called_scope);
    } else {
        fcc.called_scope = ce;
    }
#else
    fcc.called_scope = ce;
#endif

    zend_fcall_info fci = empty_fcall_info;
    fci.size = sizeof(zend_fcall_info);
    fci.no_separation = 1;
    fci.retval_ptr_ptr = retval;

    /* If 'object' is NULL, the caller wants to call this method statically. */
    if (object) {
        fci.object_ptr = object;
        fcc.object_ptr = object;
    }

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    /* We add the zval args directly from the variable arguments, va_arg(),
     * instead of using zend_fcall_info_argv() because:
     *
     *   - We can NULL-check each arg.
     *   - We circumvent an unnecessary reference count increase;
     *     zend_call_function() already copies the zvals and increments the RC.
     *   - We avoid an unnecessary heap allocation for the params.
     *   - We avoid unnecessary calls to zend_fcall_info_args_clear().
     */
    if (argc > 0) {
        zval *params[MAX_ARGS];
        zval **param_ptrs[MAX_ARGS];
        for (uint32_t i = 0; i < (uint32_t)argc; ++i) {
            zval *arg = va_arg(argv, zval *);
            if (!arg) {
                zai_sandbox_close(&sandbox);
                goto error_exit;
            }
            params[i] = arg;
            param_ptrs[i] = &params[i];
        }

        fci.param_count = (uint32_t)argc;
        fci.params = param_ptrs;
    }

    /* Marking these as 'volatile' will ensure they survive a possible long jump
     * from a zend_bailout without getting clobbered.
     */
    volatile bool should_bail = false;
    volatile int call_fn_result = FAILURE;

    /* The zend_call_method() API only supports up to two arguments so we use
     * zend_call_function() instead.
     */
    zend_try { call_fn_result = zend_call_function(&fci, &fcc TSRMLS_CC); }
    zend_catch { should_bail = true; }
    zend_end_try();

    if (should_bail) {
        /* An unclean shutdown from a zend_bailout can occur deep within a
         * userland call stack (e.g. from 'exit') which will long jump over
         * dtors and frees. If we caught an arbitrary zend_bailout here and
         * went on pretending like nothing happened, this would lead to a lot
         * of ZMM leaks and likely a number of real memory leaks so the safest
         * thing to do is clean up as best we can and then bubble up the
         * zend_bailout.
         */
        zai_sandbox_close(&sandbox);
        zend_bailout();
    }

    /* An unhandled exception will not result in a zend_bailout if there is an
     * active execution context. This is a failed call if an exception was
     * thrown. The sandbox will clean up our mess when it closes.
     */
    bool no_exception = !EG(exception);
    zai_sandbox_close(&sandbox);
    if (call_fn_result == SUCCESS && no_exception) {
        return true;
    }

error_exit:
    /* For additional safety this wrapper sets the retval to IS_NULL so that the
     * caller can expect a valid retval zval in the error cases.
     */
    ALLOC_INIT_ZVAL(*retval);
    ZVAL_NULL(*retval);
    return false;
}

bool zai_call_method_ex(zval *object, const char *method, size_t method_len, zval **retval TSRMLS_DC, int argc, ...) {
    if (!retval) return false;
    if (!object || Z_TYPE_P(object) != IS_OBJECT) {
        ALLOC_INIT_ZVAL(*retval);
        ZVAL_NULL(*retval);
        return false;
    }
    va_list argv;
    va_start(argv, argc);
    bool status = z_vcall_method_ex(object, Z_OBJCE_P(object), method, method_len, retval TSRMLS_CC, argc, argv);
    va_end(argv);
    return status;
}

bool zai_call_static_method_ex(zend_class_entry *ce, const char *method, size_t method_len, zval **retval TSRMLS_DC,
                               int argc, ...) {
    if (!retval) return false;
    if (!ce) {
        ALLOC_INIT_ZVAL(*retval);
        ZVAL_NULL(*retval);
        return false;
    }
    va_list argv;
    va_start(argv, argc);
    bool status = z_vcall_method_ex(NULL, ce, method, method_len, retval TSRMLS_CC, argc, argv);
    va_end(argv);
    return status;
}
