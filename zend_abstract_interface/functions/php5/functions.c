#include "../functions.h"

#include <sandbox/sandbox.h>
#include <stdint.h>
#include <zai_assert/zai_assert.h>

#define MAX_ARGS 3

bool zai_call_function_ex(const char *name, size_t name_len, zval **retval TSRMLS_DC, int argc, ...) {
    if (!retval) return false;

    assert(argc <= MAX_ARGS && "Increase MAX_ARGS to support more arguments.");

    if (!name || !name_len || argc < 0 || argc > MAX_ARGS) goto error_exit;

    /* Functions cannot be called outside of a request context.
     * PG(modules_activated) indicates that all of the module RINITs have been
     * called and we are in a request context.
     */
    if (!PG(modules_activated)) goto error_exit;

    zai_assert_is_lower(name, "Function names must be lowercase.");
    assert(*name != '\\' && "Function names must not have a root scope prefix '\\'.");

    zend_fcall_info_cache fcc = {0};
    fcc.initialized = 1;

    /* We look up the function handler directly from zend_fetch_function()
     * instead of calling zend_is_callable_ex() because:
     *
     *   - We always call functions by their string name and not one of the
     *     many 'callable' types, so the code path can be simplified.
     *   - We assume the caller always provides the lowercased function name so
     *     we can avoid zend_str_tolower_copy().
     *   - We assume the caller will not prefix the function name with a
     *     root-scope prefix, '\'.
     */
    if (zend_hash_find(EG(function_table), name, name_len + 1, (void **)&fcc.function_handler) != SUCCESS) {
        /* "function %s() does not exist" */
        goto error_exit;
    }

    zend_fcall_info fci = {0};
    fci.size = sizeof(zend_fcall_info);
    fci.retval_ptr_ptr = retval;

    volatile bool should_bail = false;
    volatile int call_fn_result = FAILURE;

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
        va_list argv;
        va_start(argv, argc);
        for (uint32_t i = 0; i < (uint32_t)argc; ++i) {
            zval *arg = va_arg(argv, zval *);
            if (!arg) {
                zai_sandbox_close(&sandbox);
                goto error_exit;
            }
            params[i] = arg;
            param_ptrs[i] = &params[i];
        }
        va_end(argv);

        fci.param_count = (uint32_t)argc;
        fci.params = param_ptrs;

        zend_try { call_fn_result = zend_call_function(&fci, &fcc TSRMLS_CC); }
        zend_catch { should_bail = true; }
        zend_end_try();
    } else {
        zend_try { call_fn_result = zend_call_function(&fci, &fcc TSRMLS_CC); }
        zend_catch { should_bail = true; }
        zend_end_try();
    }

    if (should_bail) {
        /* An unclean shutdown from a zend_bailout can occur deep within a
         * userland call stack which will long jump over dtors and frees. If we
         * caught an arbitrary zend_bailout here and went on pretending like
         * nothing happened, this would lead to a lot of ZMM leaks and likely a
         * number of real memory leaks so the safest thing to do is clean up as
         * best we can and then bubble up the zend_bailout.
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
    /* For additional safety this wrapper sets the retval to IS_NULL so that the caller can expect a valid retval zval
     * in the error cases. Past wrappers of zend_call_function() did not mimic this behavior and that lead to a SIGSEGV.
     */
    ALLOC_INIT_ZVAL(*retval);
    ZVAL_NULL(*retval);
    return false;
}
