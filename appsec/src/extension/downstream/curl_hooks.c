// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "curl_hooks.h"

#include <Zend/zend_exceptions.h>
#include <php.h>
#include <pthread.h>
#include <stdatomic.h>

#include <ext/handlers_api.h>

#include "../configuration.h"
#include "../logging.h"
#include "../php_helpers.h"
#include "curl_observe.h"
#include "curl_opts.h"

// CURLMSG_DONE (multi.h); CURLM_ABORTED_BY_CALLBACK (multi.h, 7.81.0)
enum {
    DD_CURLMSG_DONE = 1,
    DD_CURLM_ABORTED_BY_CALLBACK = 11,
};

static zif_handler _orig_curl_init;
static zif_handler _orig_curl_copy_handle;
static zif_handler _orig_curl_reset;
static zif_handler _orig_curl_setopt;
static zif_handler _orig_curl_setopt_array;
static zif_handler _orig_curl_exec;
static zif_handler _orig_curl_multi_add_handle;
static zif_handler _orig_curl_multi_exec;
static zif_handler _orig_curl_multi_info_read;
static zif_handler _orig_curl_multi_remove_handle;
static zif_handler _orig_curl_multi_close;

static ZEND_FUNCTION(dd_curl_init);
static ZEND_FUNCTION(dd_curl_copy_handle);
static ZEND_FUNCTION(dd_curl_reset);
static ZEND_FUNCTION(dd_curl_setopt);
static ZEND_FUNCTION(dd_curl_setopt_array);
static ZEND_FUNCTION(dd_curl_exec);
static ZEND_FUNCTION(dd_curl_multi_add_handle);
static ZEND_FUNCTION(dd_curl_multi_exec);
static ZEND_FUNCTION(dd_curl_multi_info_read);
static ZEND_FUNCTION(dd_curl_multi_remove_handle);
static ZEND_FUNCTION(dd_curl_multi_close);

static bool _into_libcurl(zif_handler handler, INTERNAL_FUNCTION_PARAMETERS);
static void _after_copy(zval *zsrc, zval *zcopy);
static bool _call_setopt(zval *zch, zend_long option, zval *value);
static void _reject_key(
    INTERNAL_FUNCTION_PARAMETERS, zend_string *key, zval *value);
#if PHP_VERSION_ID >= 80000
static void _wrap_clone_obj(zval *zch);
static zend_object *_clone_obj(zend_object *obj);
#endif
static zval *_arg(zend_execute_data *execute_data, uint32_t n);
static bool _option_number(zval *zopt, zend_long *out);
static bool _stringify_arg(zend_execute_data *execute_data, uint32_t n);

#if PHP_VERSION_ID >= 80000
// CurlHandle's clone_obj (ext/curl's object handlers are static; we get them
// from the first handle curl_init() returns, before any can be cloned)
static _Atomic(zend_object_handlers *) _curl_handlers;
static zend_object *(*_orig_clone_obj)(zend_object *obj);
static pthread_once_t _wrap_clone_once = PTHREAD_ONCE_INIT;
#endif

static bool _installed;

void dd_curl_hooks_startup(void)
{
    _installed = false;
    if (!get_global_DD_APPSEC_RASP_ENABLED()) {
        // no hook at all: curl transfers are not analysed (G4.1)
        mlog_no_php(
            dd_log_debug, "RASP is not enabled; not observing curl transfers");
        return;
    }
    if (!dd_curl_observe_startup()) {
        // the layer is off (linkage detection off, calibration refused, no
        // curl handle type): no hook either (G3.8)
        return;
    }

    // Installed after the tracer's (ddtrace_curl_handlers_startup ran in the
    // tracer's startup, before AppSec's), so ours run first and call the
    // tracer's, which call ext/curl's.
    datadog_php_zif_handler handlers[] = {
        {ZEND_STRL("curl_init"), &_orig_curl_init, ZEND_FN(dd_curl_init)},
        {ZEND_STRL("curl_copy_handle"), &_orig_curl_copy_handle,
            ZEND_FN(dd_curl_copy_handle)},
        {ZEND_STRL("curl_reset"), &_orig_curl_reset, ZEND_FN(dd_curl_reset)},
        {ZEND_STRL("curl_setopt"), &_orig_curl_setopt, ZEND_FN(dd_curl_setopt)},
        {ZEND_STRL("curl_setopt_array"), &_orig_curl_setopt_array,
            ZEND_FN(dd_curl_setopt_array)},
        {ZEND_STRL("curl_exec"), &_orig_curl_exec, ZEND_FN(dd_curl_exec)},
        {ZEND_STRL("curl_multi_add_handle"), &_orig_curl_multi_add_handle,
            ZEND_FN(dd_curl_multi_add_handle)},
        {ZEND_STRL("curl_multi_exec"), &_orig_curl_multi_exec,
            ZEND_FN(dd_curl_multi_exec)},
        {ZEND_STRL("curl_multi_info_read"), &_orig_curl_multi_info_read,
            ZEND_FN(dd_curl_multi_info_read)},
        {ZEND_STRL("curl_multi_remove_handle"), &_orig_curl_multi_remove_handle,
            ZEND_FN(dd_curl_multi_remove_handle)},
        {ZEND_STRL("curl_multi_close"), &_orig_curl_multi_close,
            ZEND_FN(dd_curl_multi_close)},
    };
    for (size_t i = 0; i < sizeof(handlers) / sizeof(handlers[0]); i++) {
        datadog_php_install_handler(handlers[i]);
    }
    _installed = true;
#if PHP_VERSION_ID >= 80000
    // ext/curl's MINIT set its handlers again (e.g. apachectl graceful). If
    // it did not, the handlers still point at _clone_obj: keep the original
    // we captured, or the next wrap would take _clone_obj for it and recurse
    zend_object_handlers *curl_handlers = atomic_load(&_curl_handlers);
    if (!curl_handlers || curl_handlers->clone_obj != _clone_obj) {
        atomic_store(&_curl_handlers, NULL);
        _orig_clone_obj = NULL;
        _wrap_clone_once = (pthread_once_t)PTHREAD_ONCE_INIT;
    }
#endif
}

bool dd_curl_hooks_installed(void) { return _installed; }

// curl_init(?string $url = null)
static ZEND_FUNCTION(dd_curl_init)
{
    _orig_curl_init(INTERNAL_FUNCTION_PARAM_PASSTHRU);

#if PHP_VERSION_ID >= 80000
    _wrap_clone_obj(return_value);
#endif
    struct dd_curl_hstate *hs = dd_curl_hstate_create(return_value);
    zval *url = _arg(execute_data, 1);
    if (hs && url) {
        dd_curl_opts_set_url(dd_curl_hstate_opts(hs), url);
    }
}

// curl_copy_handle(CurlHandle $handle)
static ZEND_FUNCTION(dd_curl_copy_handle)
{
    _orig_curl_copy_handle(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    zval *zsrc = _arg(execute_data, 1);
    if (zsrc) {
        _after_copy(zsrc, return_value);
    }
}

#if PHP_VERSION_ID >= 80000
// clone $handle: the same as curl_copy_handle() (curl_easy_duphandle(), then
// ext/curl's handlers copied), without going through it
static zend_object *_clone_obj(zend_object *obj)
{
    zend_object *clone = _orig_clone_obj(obj);
    if (clone) {
        zval zsrc;
        zval zcopy;
        ZVAL_OBJ(&zsrc, obj);
        ZVAL_OBJ(&zcopy, clone);
        _after_copy(&zsrc, &zcopy);
    }
    return clone;
}

static void _wrap_clone(void)
{
    zend_object_handlers *handlers = atomic_load(&_curl_handlers);
    if (handlers->clone_obj == _clone_obj) {
        return; // already wrapped: keep the original captured then
    }
    _orig_clone_obj = handlers->clone_obj;
    if (_orig_clone_obj) {
        handlers->clone_obj = _clone_obj;
    }
}

static void _wrap_clone_obj(zval *zch)
{
    if (Z_TYPE_P(zch) != IS_OBJECT) {
        return;
    }
    // the same handlers in every thread; _wrap_clone() runs once. Atomic: in
    // ZTS every thread's first curl_init() stores the same pointer here.
    atomic_store(
        &_curl_handlers, (zend_object_handlers *)Z_OBJ_P(zch)->handlers);
    (void)pthread_once(&_wrap_clone_once, _wrap_clone);
}
#endif

// After a copy of zsrc (zcopy, which is false if curl_copy_handle() failed):
// if zsrc is tracked, the copy is tracked too, with zsrc's options, and does
// not keep ours
// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
static void _after_copy(zval *zsrc, zval *zcopy)
{
    struct dd_curl_hstate *src = dd_curl_hstate_find(zsrc);
    if (!src) {
        return;
    }
    struct dd_curl_hstate *copy = dd_curl_hstate_create(zcopy);
    dd_curl_xfer_after_copy(src, zcopy, copy);
    if (copy) {
        dd_curl_opts_copy(dd_curl_hstate_opts(copy), dd_curl_hstate_opts(src));
    }
}

// curl_reset(CurlHandle $handle): void
// The recorded options are cleared only if the reset took effect (G4.15):
// a refused one leaves them on the handle. PHP 7.0 and 7.4 refuse a reset
// from a callback of the handle's transfer with a warning, returning NULL;
// PHP 8 throws. Whether libcurl was reset shows in CURLINFO_EFFECTIVE_URL:
// "" after a reset, still the URL of the transfer after a refused one.
static ZEND_FUNCTION(dd_curl_reset)
{
    _orig_curl_reset(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    zval *zch = _arg(execute_data, 1);
    struct dd_curl_hstate *hs = zch ? dd_curl_hstate_find(zch) : NULL;
    if (hs && !EG(exception) && !dd_curl_has_effective_url(zch)) {
        dd_curl_opts_clear(dd_curl_hstate_opts(hs));
    }
}

// curl_setopt(CurlHandle $handle, int $option, mixed $value): bool
// Records the values the analysis needs (G4.6). A Stringable URL (and
// POSTFIELDS on PHP 8) is converted here, once, and ext/curl gets the string
// (G4.8, G4.9). ext/curl may throw and still return true: what it applied is
// then not known, so the handle is not observed (G4.11). In weak mode, the
// option number may have been passed as a numeric string, a float or a bool,
// which ext/curl's parameter parsing converted without changing the
// argument: we convert it the same way (G4.7).
static ZEND_FUNCTION(dd_curl_setopt)
{
    zval *zch = _arg(execute_data, 1);
    zval *zopt = _arg(execute_data, 2);
    zend_long option = 0;
    bool known = zopt && _option_number(zopt, &option);
    bool threw = false;
    zend_object *saved_exception = NULL;
    if (known && ZEND_NUM_ARGS() >= 3 && dd_curl_opts_converts_object(option) &&
        zch && dd_curl_hstate_find(zch)) {
        threw = _stringify_arg(execute_data, 3);
    }
    if (threw) {
        // ext/curl is to apply the empty string the conversion gave, as it
        // would have, but it must not run with the exception pending
        dd_exception_save(&saved_exception);
    }
    _orig_curl_setopt(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    if (threw) {
        dd_exception_restore(&saved_exception);
    }

    struct dd_curl_hstate *hs = zch ? dd_curl_hstate_find(zch) : NULL;
    if (!hs) {
        return;
    }
    if (EG(exception)) {
        dd_curl_opts_set_unknown(dd_curl_hstate_opts(hs));
        return;
    }
    zval *zvalue = _arg(execute_data, 3);
    if (Z_TYPE_P(return_value) != IS_TRUE || ZEND_NUM_ARGS() < 3 || !zopt ||
        !zvalue) {
        return;
    }
    if (!known) {
        // some option was applied; we can't tell which
        dd_curl_opts_set_unknown(dd_curl_hstate_opts(hs));
        return;
    }
    dd_curl_opts_set(dd_curl_hstate_opts(hs), option, zvalue);
}

// curl_setopt_array(CurlHandle $handle, array $options): bool
// For a handle we track, the elements are applied as curl_setopt() calls, in
// order (G4.12): through the hook above, which records each as it is applied,
// so that after a failure the recorded options are exactly the applied ones.
// The loop stops at the first element that returns false or leaves an
// exception pending, as ext/curl's does. A string key is for ext/curl to
// reject, at its position (and with its message). An untracked handle is
// ext/curl's alone.
static ZEND_FUNCTION(dd_curl_setopt_array)
{
    zval *zch = _arg(execute_data, 1);
    zval *zarr = _arg(execute_data, 2);
    if (!zch || !zarr || ZEND_NUM_ARGS() != 2 || Z_TYPE_P(zarr) != IS_ARRAY ||
        !dd_curl_hstate_find(zch)) {
        _orig_curl_setopt_array(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    // the loop may run app code (__toString(), destructors, user hooks)
    zval options;
    ZVAL_COPY(&options, zarr);
    zend_ulong option;
    zend_string *key;
    zval *value;
    bool ok = true;
    bool rejected = false;
    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL(options), option, key, value)
    {
        if (key) {
            rejected = true;
            _reject_key(INTERNAL_FUNCTION_PARAM_PASSTHRU, key, value);
            break;
        }
        if (!_call_setopt(zch, (zend_long)option, value)) {
            ok = false;
            break;
        }
    }
    ZEND_HASH_FOREACH_END();
    zval_ptr_dtor(&options);
    if (!rejected) {
        RETVAL_BOOL(ok);
    } // else ext/curl set the return value (or threw)
}

// curl_exec(CurlHandle $handle): string|bool
// Entered inside libcurl (from an app callback), it does nothing (G4.3).
static ZEND_FUNCTION(dd_curl_exec)
{
    if (dd_curl_eval_in_libcurl()) {
        _orig_curl_exec(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }
    zval *zch = _arg(execute_data, 1);
    struct dd_curl_hstate *hs = zch ? dd_curl_xfer_begin(zch, 0) : NULL;
    if (!hs) {
        // not observed, but the app's callbacks run inside libcurl
        if (_into_libcurl(_orig_curl_exec, INTERNAL_FUNCTION_PARAM_PASSTHRU)) {
            zend_bailout();
        }
        return;
    }

    // the handle cannot go away while curl_exec() holds it as an argument
    dd_curl_xfer_set_in_exec(hs, true);
    bool bailout =
        _into_libcurl(_orig_curl_exec, INTERNAL_FUNCTION_PARAM_PASSTHRU);
    if (bailout) {
        zend_bailout();
    }
    // curl_exec() returns false on any error (CURLcode not CURLE_OK)
    dd_curl_xfer_end(hs, Z_TYPE_P(return_value) != IS_FALSE);
}

// curl_multi_add_handle(CurlMultiHandle $multi_handle, CurlHandle $handle):
//     int
static ZEND_FUNCTION(dd_curl_multi_add_handle)
{
    zval *zmh = _arg(execute_data, 1);
    zval *zch = _arg(execute_data, 2);
    uintptr_t multi = zmh ? dd_curl_multi_key(zmh, true) : 0;

    _orig_curl_multi_add_handle(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    // CURLM_OK is 0
    bool added = Z_TYPE_P(return_value) == IS_LONG &&
                 Z_LVAL_P(return_value) == 0 && !EG(exception);
    if (zch && multi) {
        // the transfer starts at the first curl_multi_exec (G4.17)
        if (added) {
            dd_curl_xfer_added_to_multi(zch, multi);
        } else {
            dd_curl_xfer_add_failed(zch, multi);
        }
    }
}

// curl_multi_exec(CurlMultiHandle $multi_handle, &$still_running): int
// Entered inside libcurl it does nothing (G4.3), and the transfers added to
// that multi handle that were not started are not observed anymore (G4.17,
// G6.12).
static ZEND_FUNCTION(dd_curl_multi_exec)
{
    zval *zmh = _arg(execute_data, 1);
    uintptr_t multi = zmh ? dd_curl_multi_key(zmh, false) : 0;
    if (dd_curl_eval_in_libcurl()) {
        dd_curl_xfer_drop_pending(multi);
        _orig_curl_multi_exec(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }
    dd_curl_xfer_reassert_multi(multi);
    // the transfers added since the last exec start now: libcurl reads their
    // options now. A block there stops the request before any connection.
    if (dd_curl_xfer_start_pending(multi)) {
        RETURN_LONG(DD_CURLM_ABORTED_BY_CALLBACK);
    }
    if (_into_libcurl(
            _orig_curl_multi_exec, INTERNAL_FUNCTION_PARAM_PASSTHRU)) {
        zend_bailout();
    }
    // CURLM_OK with nothing running: every transfer of the multi is over,
    // whether libcurl completed it or ended it with an error. A refused exec
    // (e.g. CURLM_RECURSIVE_API_CALL) leaves $still_running as it was and
    // flushes nothing (G4.18).
    zval *running = _arg(execute_data, 2); // already dereferenced
    if (Z_TYPE_P(return_value) == IS_LONG &&
        Z_LVAL_P(return_value) == 0 && // CURLM_OK
        running && Z_TYPE_P(running) == IS_LONG && Z_LVAL_P(running) == 0) {
        dd_curl_xfer_flush_multi(multi);
    }
}

// curl_multi_info_read(CurlMultiHandle $multi_handle, &$queued_messages = null)
//     : array|false
// A transfer reported done is ended.
static ZEND_FUNCTION(dd_curl_multi_info_read)
{
    _orig_curl_multi_info_read(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    zval *zmh = _arg(execute_data, 1);
    // inside libcurl: does nothing, the transfer is reported by the next
    // hook that sees it (G4.3)
    if (!zmh || Z_TYPE_P(return_value) != IS_ARRAY ||
        dd_curl_eval_in_libcurl()) {
        return;
    }
    zval *msg = zend_hash_str_find(Z_ARRVAL_P(return_value), ZEND_STRL("msg"));
    zval *zch =
        zend_hash_str_find(Z_ARRVAL_P(return_value), ZEND_STRL("handle"));
    if (msg && zch && Z_TYPE_P(msg) == IS_LONG &&
        Z_LVAL_P(msg) == DD_CURLMSG_DONE) {
        zval *result =
            zend_hash_str_find(Z_ARRVAL_P(return_value), ZEND_STRL("result"));
        bool completed = result && Z_TYPE_P(result) == IS_LONG &&
                         Z_LVAL_P(result) == 0; // CURLE_OK
        dd_curl_xfer_end_in_multi(
            zch, dd_curl_multi_key(zmh, false), completed);
    }
}

// curl_multi_remove_handle(CurlMultiHandle $multi_handle, CurlHandle $handle)
//     : int
static ZEND_FUNCTION(dd_curl_multi_remove_handle)
{
    if (dd_curl_eval_in_libcurl()) {
        // does not observe (G4.3), but a pending handle is dropped (G4.17)
        _orig_curl_multi_remove_handle(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        zval *nzmh = _arg(execute_data, 1);
        zval *nzch = _arg(execute_data, 2);
        if (nzmh && nzch && Z_TYPE_P(return_value) == IS_LONG &&
            Z_LVAL_P(return_value) == 0) { // CURLM_OK
            dd_curl_xfer_drop_pending_handle(
                nzch, dd_curl_multi_key(nzmh, false));
        }
        return;
    }
    if (_into_libcurl(
            _orig_curl_multi_remove_handle, INTERNAL_FUNCTION_PARAM_PASSTHRU)) {
        zend_bailout();
    }

    zval *zmh = _arg(execute_data, 1);
    zval *zch = _arg(execute_data, 2);
    if (zmh && zch) {
        uintptr_t multi = dd_curl_multi_key(zmh, false);
        // CURLM_OK is 0
        if (Z_TYPE_P(return_value) == IS_LONG && Z_LVAL_P(return_value) == 0) {
            dd_curl_xfer_removed_from_multi(zch, multi);
        }
        // whether libcurl completed it is not known here
        dd_curl_xfer_end_in_multi(zch, multi, false);
    }
}

// curl_multi_close(CurlMultiHandle $multi_handle): void
static ZEND_FUNCTION(dd_curl_multi_close)
{
    zval *zmh = _arg(execute_data, 1);
    uintptr_t multi = zmh ? dd_curl_multi_key(zmh, false) : 0;

    if (dd_curl_eval_in_libcurl()) { // does nothing (G4.3)
        _orig_curl_multi_close(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }
    if (_into_libcurl(
            _orig_curl_multi_close, INTERNAL_FUNCTION_PARAM_PASSTHRU)) {
        zend_bailout();
    }

    dd_curl_xfer_end_multi(multi);
}

// Calls the original handler of a function that runs libcurl transfers, and
// with them possibly the app's code (dd_curl_eval_libcurl_enter). Returns
// whether it bailed out (to be re-raised by the caller).
static bool _into_libcurl(zif_handler handler, INTERNAL_FUNCTION_PARAMETERS)
{
    volatile bool bailout = false;
    dd_curl_eval_libcurl_enter();
    zend_try { handler(INTERNAL_FUNCTION_PARAM_PASSTHRU); }
    zend_catch { bailout = true; }
    zend_end_try();
    dd_curl_eval_libcurl_leave();
    return bailout;
}

// Calls the PHP function curl_setopt() (so, this file's hook) like the app
// would; returns whether it applied the option: it returned true and no
// exception is pending. If it cannot be called (disable_functions), nothing
// is applied and the array call fails.
static bool _call_setopt(zval *zch, zend_long option, zval *value)
{
    zval fname;
    zval ret;
    zval args[3];
    ZVAL_STRINGL(&fname, "curl_setopt", sizeof("curl_setopt") - 1);
    ZVAL_COPY(&args[0], zch);
    ZVAL_LONG(&args[1], option);
    ZVAL_DEREF(value);
    ZVAL_COPY(&args[2], value);
    ZVAL_UNDEF(&ret);
    bool ok =
        call_user_function(NULL, NULL, &fname, &ret, 3, args) == SUCCESS &&
        Z_TYPE(ret) == IS_TRUE && !EG(exception);
    zval_ptr_dtor(&ret);
    zval_ptr_dtor(&args[2]);
    zval_ptr_dtor(&args[0]);
    zval_ptr_dtor(&fname);
    return ok;
}

// A string key is an error for ext/curl, in a message that depends on the PHP
// version: let it raise it, by giving it an array of just that element
// (it checks the key first).
static void _reject_key(
    INTERNAL_FUNCTION_PARAMETERS, zend_string *key, zval *value)
{
    zval *slot = ZEND_CALL_ARG(execute_data, 2);
    zval one;
    array_init_size(&one, 1);
    Z_TRY_ADDREF_P(value);
    zend_hash_add_new(Z_ARRVAL(one), key, value);
    zval_ptr_dtor(slot);
    ZVAL_COPY_VALUE(slot, &one);
    _orig_curl_setopt_array(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

// If the n-th argument is an object with __toString(), replaces it in the
// call frame by the string it gives, so that ext/curl does not call the
// method again. Returns whether the conversion threw, in which case the
// argument is the empty string, as ext/curl takes it (PHP 7.0-7.3 end in a
// fatal error, as they do for ext/curl).
static bool _stringify_arg(zend_execute_data *execute_data, uint32_t n)
{
    zval *slot = ZEND_CALL_ARG(execute_data, n);
    zval *value = slot;
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) != IS_OBJECT || !Z_OBJCE_P(value)->__tostring) {
        return false;
    }
    zend_string *str = zval_get_string(value);
    // release the object first: a throwing __destruct() (the argument may
    // have held its last reference) must count as a throw too
    zval_ptr_dtor(slot);
    ZVAL_STR(slot, str);
    return EG(exception) != NULL;
}

// The option number ext/curl took from zopt: an int, or what weak-mode
// parameter parsing (Z_PARAM_LONG / "l") converts silently or with only a
// diagnostic: a numeric (or, with a diagnostic, leading-numeric) string, a
// float that fits (truncated), a bool or null. ext/curl accepted it (its
// function did not fail), so false means only "not known".
static bool _option_number(zval *zopt, zend_long *out)
{
    double dval;
    switch (Z_TYPE_P(zopt)) {
    case IS_LONG:
        *out = Z_LVAL_P(zopt);
        return true;
    case IS_NULL:
    case IS_FALSE:
        *out = 0;
        return true;
    case IS_TRUE:
        *out = 1;
        return true;
    case IS_DOUBLE:
        dval = Z_DVAL_P(zopt);
        break;
    case IS_STRING: {
        zend_long lval;
        // allowing errors: trailing data, about which ext/curl's parsing
        // raised its diagnostic already (ext/compatibility.h gives PHP 7
        // the PHP 8 signature)
        int type = (int)is_numeric_string_ex(
            Z_STRVAL_P(zopt), Z_STRLEN_P(zopt), &lval, &dval, true, NULL, NULL);
        if (type == IS_LONG) {
            *out = lval;
            return true;
        }
        if (type != IS_DOUBLE) {
            return false;
        }
        break;
    }
    default:
        return false;
    }
    if (zend_isnan(dval) || !ZEND_DOUBLE_FITS_LONG(dval)) {
        return false;
    }
    *out = zend_dval_to_lval(dval);
    return true;
}

// The n-th (1-based) argument, dereferenced; NULL if not passed
static zval *_arg(zend_execute_data *execute_data, uint32_t n)
{
    if (ZEND_NUM_ARGS() < n) {
        return NULL;
    }
    zval *zv = ZEND_CALL_ARG(execute_data, n);
    if (Z_ISUNDEF_P(zv)) {
        return NULL;
    }
    ZVAL_DEREF(zv);
    return zv;
}
