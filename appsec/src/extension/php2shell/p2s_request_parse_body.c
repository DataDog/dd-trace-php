// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

#include "p2s_request_parse_body.h"

#include <SAPI.h>
#include <Zend/zend_API.h>
#include <php.h>
#include <stdatomic.h>

#include "../ddappsec.h"   // TSRMLS_CACHE as local-dynamic: no STATIC_TLS
#include "../php_compat.h" // zif_handler (PHP 7.0)
#include "p2s_hook.h"
#include "p2s_oom_guard.h"
#include "p2s_policy.h"
#include "p2s_startup_parse.h"

#if PHP_VERSION_ID >= 80400
// The handler of request_parse_body() we replaced (see Windows at the top of
// p2s_startup_parse.c). Only the handler is kept, never the zend_function.
static zif_handler _request_parse_body_orig;
// Whether the displacement of the handler was logged; process-wide.
static atomic_bool _displaced_logged_request_parse_body;

static ZEND_NAMED_FUNCTION(_request_parse_body);
static size_t _request_parse_body_bound(zend_execute_data *execute_data);
static zend_long _rpb_option_long(zval *value, zend_long ini);
static void _request_parse_body_parse(void *ctx);
static zif_handler _rpb_live_handler(void);
#endif

// Called once, from the zend_extension startup hook (dd_php2shell_startup(),
// after every module MINIT), when the policy allows hooking.
void dd_p2s_request_parse_body_install(void)
{
#if PHP_VERSION_ID >= 80400
    // ext/standard is built in, so request_parse_body() exists by now:
    // php_module_startup() applies disable_functions only after
    // zend_startup_extensions(). The ZTS per-thread function tables are copied
    // from this one later, in zend_post_startup(), so they all get the
    // wrapper. Only the handler is saved: disable_functions frees the
    // function afterwards, so a saved zend_function would dangle (see
    // dd_p2s_live_function). The function table is rebuilt with the original
    // handler on the next startup, where this runs again.
    zend_function *fn = zend_hash_str_find_ptr(
        CG(function_table), ZEND_STRL("request_parse_body"));
    ZEND_ASSERT(fn != NULL && fn->type == ZEND_INTERNAL_FUNCTION);
    if (fn != NULL && fn->type == ZEND_INTERNAL_FUNCTION &&
        fn->internal_function.handler != _request_parse_body) {
        _request_parse_body_orig = fn->internal_function.handler;
        fn->internal_function.handler = _request_parse_body;
    }
#endif
}

// Called from MSHUTDOWN (dd_php2shell_mshutdown()), after the guard has reset
// the heap's handlers.
void dd_p2s_request_parse_body_uninstall(void)
{
#if PHP_VERSION_ID >= 80400
    // The function table is rebuilt on the next startup; the function itself
    // may already be freed.
    _request_parse_body_orig = NULL;
    atomic_store(&_displaced_logged_request_parse_body, false);
#endif
}

void dd_p2s_request_parse_body_check(void)
{
#if PHP_VERSION_ID >= 80400
    zif_handler rpb = _rpb_live_handler();
    dd_p2s_hook_check_displaced(_request_parse_body_orig != NULL && rpb != NULL,
        rpb == _request_parse_body, &_displaced_logged_request_parse_body,
        "request_parse_body()", DD_P2S_EFFECT_OFF);
#endif
}

void dd_p2s_request_parse_body_minfo(void)
{
#if PHP_VERSION_ID >= 80400
    zif_handler rpb = _rpb_live_handler();
    dd_p2s_hook_minfo_row("PHP2Shell request_parse_body() hook",
        _request_parse_body_orig != NULL && rpb != NULL,
        rpb == _request_parse_body);
#endif
}

#if PHP_VERSION_ID >= 80400
typedef struct {
    zend_execute_data *execute_data;
    zval *return_value;
} dd_request_parse_body_call;

static ZEND_NAMED_FUNCTION(_request_parse_body)
{
    // Same gate as _treat_data (p2s_startup_parse.c). A call made while a
    // parse is armed forwards unarmed.
    if (dd_p2s_guard_in_window() || !dd_p2s_policy_armed()) {
        _request_parse_body_orig(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    dd_request_parse_body_call call = {
        .execute_data = execute_data, .return_value = return_value};
    dd_p2s_startup_parse_run_window(_request_parse_body_parse, &call,
        !dd_p2s_policy_patched(), _request_parse_body_bound(execute_data),
        dd_p2s_startup_parse_multipart_body(), DD_PHASE_REQUEST_PARSE_BODY);
}

// The input bound of request_parse_body(): the post_max_size it will apply (the
// "post_max_size" entry of its $options argument, else the INI value), raised
// to the bytes of a body already read (see
// dd_p2s_startup_parse_post_body_bound). The original reads its options only
// once it runs, so the entry is looked up here the way
// cache_request_parse_body_options() does: keys compared case-insensitively,
// the last match winning. A non-array argument or an integer key makes the
// original throw before it reads the body, so the INI value is as good as any
// there. No user code runs between this scan and the original's read, so the
// value cannot change in between.
static size_t _request_parse_body_bound(zend_execute_data *execute_data)
{
    zend_long post_max_size = SG(post_max_size);
    zval *options =
        ZEND_NUM_ARGS() >= 1 ? ZEND_CALL_ARG(execute_data, 1) : NULL;
    if (options != NULL) {
        ZVAL_DEREF(options);
    }
    if (options != NULL && Z_TYPE_P(options) == IS_ARRAY) {
        zend_string *key;
        zval *val;
        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(options), key, val)
        {
            if (key == NULL) {
                continue;
            }
            if (zend_string_equals_literal_ci(key, "post_max_size")) {
                post_max_size = _rpb_option_long(val, SG(post_max_size));
            }
        }
        ZEND_HASH_FOREACH_END();
    }
    return dd_p2s_startup_parse_post_body_bound(post_max_size);
}

// The value of one request_parse_body() option, as
// cache_request_parse_body_option() reads it: an int as is, a string parsed as
// an INI quantity (the original emits any warning about it; it is dropped
// here). A value of another type makes the original throw before it reads the
// body; `ini` is returned for it.
static zend_long _rpb_option_long(zval *value, zend_long ini)
{
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) == IS_LONG) {
        return Z_LVAL_P(value);
    }
    if (Z_TYPE_P(value) == IS_STRING) {
        zend_string *errstr = NULL;
        zend_long result = zend_ini_parse_quantity(Z_STR_P(value), &errstr);
        if (errstr != NULL) {
            zend_string_release(errstr);
        }
        return result;
    }
    return ini;
}

static void _request_parse_body_parse(void *ctx)
{
    dd_request_parse_body_call *call = ctx;
    _request_parse_body_orig(call->execute_data, call->return_value);
}

// The handler of request_parse_body() now, or NULL if the function is gone.
static zif_handler _rpb_live_handler(void)
{
    return dd_p2s_live_handler(ZEND_STRL("request_parse_body"));
}
#endif
