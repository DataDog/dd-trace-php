#include "../methods.h"

#include <Zend/zend_interfaces.h>
#include <sandbox/sandbox.h>
#include <zai_assert/zai_assert.h>

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

static bool z_call_method_without_args_ex(zval *object, zend_class_entry *ce, const char *method, size_t method_len,
                                          zval **retval TSRMLS_DC) {
    if (!ce || !method || !method_len || !retval) return false;

    /* Methods cannot be called outside of a request context.
     * PG(modules_activated) indicates that all of the module RINITs have been
     * called and we are in a request context.
     */
    if (!PG(modules_activated)) return false;

    /* Prevent a potential dangling pointer in case the caller accidentally
     * sent in an allocated retval.
     */
    if (*retval != NULL) return false;

    /* If 'object' is NULL, the caller wants to call this method statically. */
    zval **obj = object ? &object : NULL;

    /* This one gets me all the time. Trying to save myself 5 minutes for the
     * next time it inevitably happens.
     */
    zai_assert_is_lower(method, "Don't forget to send those method names in all lowercase. ;)");

    /* There is an important ZEND_ACC_ALLOW_STATIC check that occurs within the
     * VM that is circumvented when calling a method outside of a VM context
     * via zend_call_method(). Bypassing this check can result in an
     * application crash therefore we must look up the function handler and
     * perform the check manually here.
     *
     * https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_vm_def.h#L2639-L2642
     */
    zend_function *func = NULL;
    if (zend_hash_find(&ce->function_table, method, (method_len + 1), (void **)&func) == FAILURE) return false;

    /* Calling a non-static method statically can result in a SIGSEGV because
     * the VM acts as a NULL check on the object before calling the function
     * handler and we are bypassing that check with a direct call to
     * zend_call_method(). We only want to allow this behavior on a non-static
     * method if the function has been explicitly marked with
     * ZEND_ACC_ALLOW_STATIC signifying that it is safe to do so.
     */
    if (!obj && func->common.scope && !(func->common.fn_flags & (ZEND_ACC_STATIC | ZEND_ACC_ALLOW_STATIC))) {
        // "Non-static method %s::%s() cannot be called statically"
        return false;
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
        return false;
    }

    bool ret = false;

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    zend_try {
        /* We cannot use zend_call_method_with_0_params() here since it is a
         * macro that masks sizeof() and therefore does not calculate the
         * method len correctly without using a string literal.
         */
        zend_call_method(obj, ce, &func, method, method_len, retval, 0, NULL, NULL TSRMLS_CC);
        /* An unhandled exception will not result in a zend_bailout if there is
         * an active execution context. This is a failed call if an exception
         * was thrown. The sandbox will clean up our mess when it closes.
         */
        ret = !EG(exception);
    }
    zend_catch {
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
    zend_end_try();

    zai_sandbox_close(&sandbox);

    return ret;
}

bool zai_call_method_without_args_ex(zval *object, const char *method, size_t method_len, zval **retval TSRMLS_DC) {
    if (!object || Z_TYPE_P(object) != IS_OBJECT) return false;
    return z_call_method_without_args_ex(object, Z_OBJCE_P(object), method, method_len, retval TSRMLS_CC);
}

bool zai_call_static_method_without_args_ex(zend_class_entry *ce, const char *method, size_t method_len,
                                            zval **retval TSRMLS_DC) {
    if (!ce) return false;
    return z_call_method_without_args_ex(NULL, ce, method, method_len, retval TSRMLS_CC);
}
