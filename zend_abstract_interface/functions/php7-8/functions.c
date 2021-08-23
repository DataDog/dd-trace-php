#include "../functions.h"

#include <sandbox/sandbox.h>
#include <zai_assert/zai_assert.h>

#define MAX_ARGS 3

bool zai_call_function_ex(const char *name, size_t name_len, zval *retval, int argc, ...) {
    if (!retval) return false;

    /* For consistency with zend_call_function(), this wrapper also initializes
     * the retval to IS_UNDEF so that the caller can expect an undefined retval
     * in the error cases. Past wrappers of zend_call_function() did not mimic
     * this behavior and that lead to a SIGSEGV.
     *
     * https://github.com/php/php-src/blob/PHP-8.0.3/Zend/zend_execute_API.c#L673
     */
    ZVAL_UNDEF(retval);

    assert(argc <= MAX_ARGS && "Increase MAX_ARGS to support more arguments.");

    if (!name || !name_len || argc < 0 || argc > MAX_ARGS) return false;

    /* Functions cannot be called outside of a request context.
     * PG(modules_activated) indicates that all of the module RINITs have been
     * called and we are in a request context.
     */
    if (!PG(modules_activated)) return false;

    zai_assert_is_lower(name, "Function names must be lowercase.");
    assert(*name != '\\' && "Function names must not have a root scope prefix '\\'.");

    zend_fcall_info_cache fcc = {0};
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
#if PHP_VERSION_ID < 70300
    zval *funczv = zend_hash_find(EG(function_table), zsname);
    fcc.function_handler = funczv == NULL ? NULL : Z_FUNC_P(funczv);
    fcc.initialized = 1;
#else
    fcc.function_handler = zend_fetch_function(zsname);
#endif
    ZSTR_ALLOCA_FREE(zsname, use_heap);

    /* "function %s() does not exist" */
    if (!fcc.function_handler) return false;

    zend_fcall_info fci = {0};
    fci.size = sizeof(zend_fcall_info);
    fci.retval = retval;

    bool should_bail = false;
    ZEND_RESULT_CODE call_fn_result = FAILURE;

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
        zval params[MAX_ARGS];
        va_list argv;
        va_start(argv, argc);
        for (uint32_t i = 0; i < (uint32_t)argc; ++i) {
            zval *arg = va_arg(argv, zval *);
            if (!arg) {
                zai_sandbox_close(&sandbox);
                return false;
            }
            /* Although we could copy the zval arg into the params array with
             * direct assignment:
             *
             *   params[i] = *arg;
             *
             * This will also copy over any additional metadata stored on the
             * zval from 'u2'. As @bwoebi pointed out:
             *
             *   "The canonical way to assign a zval would be actually
             *    ZVAL_COPY_VALUE(). This copies the zend_value as two
             *    individual 32 bit assignments on 32 bit environments and
             *    notably does not copy u2."
             */
            ZVAL_COPY_VALUE(&params[i], arg);
        }
        va_end(argv);

        fci.param_count = (uint32_t)argc;
        fci.params = params;

        zend_try { call_fn_result = zend_call_function(&fci, &fcc); }
        zend_catch { should_bail = true; }
        zend_end_try();
    } else {
        zend_try { call_fn_result = zend_call_function(&fci, &fcc); }
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
    bool ret = (call_fn_result == SUCCESS && !EG(exception));
    zai_sandbox_close(&sandbox);
    return ret;
}
