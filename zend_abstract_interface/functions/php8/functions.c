#include "../functions.h"

#include <sandbox/sandbox.h>
#include <zai_assert/zai_assert.h>

bool zai_call_function(const char *name, size_t name_len, zval *retval, int argc, ...) {
    if (!retval) return false;

    /* For consistency with zend_call_function(), this wrapper also initializes
     * the retval to IS_UNDEF so that the caller can expect an undefined retval
     * in the error cases. Past wrappers of zend_call_function() did not mimic
     * this behavior and that lead to a SIGSEGV.
     *
     * https://github.com/php/php-src/blob/PHP-8.0.3/Zend/zend_execute_API.c#L673
     */
    ZVAL_UNDEF(retval);

    if (!name || !name_len || argc < 0) return false;

    /* Functions cannot be called outside of a request context.
     * PG(modules_activated) indicates that all of the module RINITs have been
     * called and we are in a request context.
     */
    if (!PG(modules_activated)) return false;

    zai_assert_is_lower(name, "Function names must be lowercase.");
    assert(*name != '\\' && "Function names must not have a root scope prefix '\\'.");

    zend_function *func = NULL;
    zend_string *zsname = NULL;
    ALLOCA_FLAG(use_heap)

    /* We look up the function handler directly from zend_fetch_function()
     * instead of calling zend_is_callable_ex() because:
     *
     *   - We always call functions by their string name and not one of the
     *     many 'callable' types, so the code path can be simplified.
     *   - We assume the caller always provides the lowercased function name so
     *     we can avoid zend_str_tolower_copy().
     *   - We assume the caller will not prefix the function name with a
     *     root-scope prefix, '\'.
     *
     * In addition, we look up the function handler from zend_fetch_function()
     * instead of directly from the EG(function_table) to ensure that the
     * runtime cache for userland functions will be initialized.
     */
    ZSTR_ALLOCA_ALLOC(zsname, name_len, use_heap);
    memcpy(ZSTR_VAL(zsname), name, (name_len + 1));
    func = zend_fetch_function(zsname);
    ZSTR_ALLOCA_FREE(zsname, use_heap);

    /* "function %s() does not exist" */
    if (!func) return false;

    zend_fcall_info_cache fcc = {0};
    fcc.function_handler = func;

    zend_fcall_info fci = {0};
    fci.size = sizeof(zend_fcall_info);
    fci.retval = retval;

    /* We add the zval args directly from the variable arguments API instead of
     * using zend_fcall_info_argv() which allows us to NULL-check each arg. It
     * also allows us to avoid an unnecessary call to
     * zend_fcall_info_args_clear() since the 'param{s|_count}' members in
     * 'zend_fcall_info' will always be zeroed out in our case.
     */
    zval params[argc];
    if (argc > 0) {
        va_list argv;
        va_start(argv, argc);
        for (uint32_t i = 0; i < (uint32_t)argc; ++i) {
            zval *arg = va_arg(argv, zval *);
            if (!arg) return false;
            params[i] = *arg;
        }
        va_end(argv);

        fci.param_count = (uint32_t)argc;
        fci.params = params;
    }

    bool ret = false;

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    zend_try {
        zend_result result = zend_call_function(&fci, &fcc);
        /* An unhandled exception will not result in a zend_bailout if there is
         * an active execution context. This is a failed call if an exception
         * was thrown. The sandbox will clean up our mess when it closes.
         */
        ret = (result == SUCCESS && !EG(exception));
    }
    zend_catch {
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
    zend_end_try();

    zai_sandbox_close(&sandbox);

    return ret;
}

bool zai_call_function_without_args(const char *name, size_t name_len, zval *retval) {
    return zai_call_function(name, name_len, retval, 0);
}
