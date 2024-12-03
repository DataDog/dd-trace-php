// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "user_tracking.h"
#include "commands/request_exec.h"
#include "ddappsec.h"
#include "dddefs.h"
#include "ddtrace.h"
#include "helper_process.h"
#include "logging.h"
#include "php_compat.h"
#include "request_abort.h"
#include "request_lifecycle.h"
#include "string_helpers.h"
#include "tags.h"
#include <Zend/zend_exceptions.h>
#include <Zend/zend_string.h>
#include <ext/hash/php_hash.h>

static THREAD_LOCAL_ON_ZTS user_collection_mode _user_mode = user_mode_disabled;

static zend_string *_user_mode_anon_zstr;
static zend_string *_user_mode_ident_zstr;
static zend_string *_user_mode_disabled_zstr;
static zend_string *_sha256_algo_zstr;

static void (*_ddtrace_set_user)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

#if PHP_VERSION_ID < 80000
typedef const php_hash_ops *(*hash_fetch_ops_t)(
    const char *algo, size_t algo_len);
static hash_fetch_ops_t _hash_fetch_ops;
#endif

static PHP_FUNCTION(set_user_wrapper)
{
    if (DDAPPSEC_G(active) || UNEXPECTED(get_global_DD_APPSEC_TESTING())) {
        zend_string *user_id = NULL;
        HashTable *metadata = NULL;
        zend_bool propagate = false;
        if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(),
                "S|hb", &user_id, &metadata, &propagate) == SUCCESS) {
            if (user_id != NULL) {
                dd_find_and_apply_verdict_for_user(user_id);
            }
        }
    }

    // This shouldn't be necessary, if it is we have a bug
    if (_ddtrace_set_user == NULL) {
        mlog(dd_log_debug, "Invalid DDTrace\\set_user, this shouldn't happen");
        return;
    }

    _ddtrace_set_user(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

void dd_user_tracking_startup(void)
{
    _user_mode_ident_zstr = zend_string_init_interned(
        LSTRARG("identification"), 1 /* persistent */);
    _user_mode_anon_zstr =
        zend_string_init_interned(LSTRARG("anonymization"), 1 /* persistent */);
    _user_mode_disabled_zstr =
        zend_string_init_interned(LSTRARG("disabled"), 1 /* persistent */);
    _sha256_algo_zstr =
        zend_string_init_interned(LSTRARG("sha256"), 1 /* persistent */);

#if PHP_VERSION_ID < 80000
    _hash_fetch_ops =
        (hash_fetch_ops_t)dlsym(RTLD_DEFAULT, "php_hash_fetch_ops");
    if (!_hash_fetch_ops) {
        mlog(dd_log_warning, "Failed to load php_hash_fetch_ops: %s",
            dlerror()); // NOLINT(concurrency-mt-unsafe)
    }
#endif

    if (!dd_trace_loaded()) {
        return;
    }
    zend_function *set_user = zend_hash_str_find_ptr(
        CG(function_table), LSTRARG("ddtrace\\set_user"));
    if (set_user != NULL) {
        _ddtrace_set_user = set_user->internal_function.handler;
        set_user->internal_function.handler = PHP_FN(set_user_wrapper);
    } else {
        bool testing = get_global_DD_APPSEC_TESTING();
        if (!testing) {
            // Avoid logging on MINIT during tests
            mlog(dd_log_warning, "DDTrace\\set_user not found");
        }
    }
}

void dd_user_tracking_shutdown(void)
{
    if (_ddtrace_set_user != NULL) {
        zend_function *set_user = zend_hash_str_find_ptr(
            CG(function_table), LSTRARG("ddtrace\\set_user"));
        if (set_user != NULL) {
            set_user->internal_function.handler = _ddtrace_set_user;
        }
    }
}

void dd_find_and_apply_verdict_for_user(zend_string *nonnull user_id)
{
    if (!DDAPPSEC_G(active) && UNEXPECTED(!get_global_DD_APPSEC_TESTING())) {
        return;
    }

    if (ZSTR_LEN(user_id) == 0) {
        mlog(dd_log_debug, "Empty user name, ignoring");
        return;
    }

    dd_conn *conn = dd_helper_mgr_cur_conn();
    if (conn == NULL) {
        mlog(dd_log_debug, "No connection; unable to check user");
        return;
    }

    zval user_id_zv;
    ZVAL_STR_COPY(&user_id_zv, user_id);

    zval data_zv;
    ZVAL_ARR(&data_zv, zend_new_array(1));
    zend_hash_str_add_new(
        Z_ARRVAL(data_zv), "usr.id", sizeof("usr.id") - 1, &user_id_zv);

    dd_result res = dd_request_exec(conn, &data_zv, false);
    zval_ptr_dtor(&data_zv);

    dd_tags_set_event_user_id(user_id);

    if (dd_req_is_user_req()) {
        if (res == dd_should_block || res == dd_should_redirect) {
            dd_req_call_blocking_function(res);
        }
    } else {
        if (res == dd_should_block) {
            dd_request_abort_static_page();
        } else if (res == dd_should_redirect) {
            dd_request_abort_redirect();
        }
    }
}

bool dd_parse_user_collection_mode(
    zai_str value, zval *nonnull decoded_value, bool persistent)
{
    if (!value.len) {
        return false;
    }

    if (dd_string_equals_lc(value.ptr, value.len, ZEND_STRL("ident")) ||
        dd_string_equals_lc(
            value.ptr, value.len, ZEND_STRL("identification")) ||
        dd_string_equals_lc(value.ptr, value.len, ZEND_STRL("extended"))) {
        _user_mode = user_mode_ident;
    } else if (dd_string_equals_lc(value.ptr, value.len, ZEND_STRL("anon")) ||
               dd_string_equals_lc(
                   value.ptr, value.len, ZEND_STRL("anonymization")) ||
               dd_string_equals_lc(value.ptr, value.len, ZEND_STRL("safe"))) {
        _user_mode = user_mode_anon;
    } else { // If the value is disabled or an unknown value, we disable user ID
             // collection

        if (!get_global_DD_APPSEC_TESTING()) {
            mlog_g(dd_log_warning, "Unknown user collection mode: %.*s",
                (int)value.len, value.ptr);
        }
        _user_mode = user_mode_disabled;
    }

    ZVAL_STR(decoded_value, zend_string_init(value.ptr, value.len, persistent));

    return true;
}

zend_string *nullable dd_user_info_anonymize(zend_string *nonnull user_id)
{
    zend_string *digest;
    const php_hash_ops *ops;
    void *context;

#if PHP_VERSION_ID < 80000
    if (!_hash_fetch_ops) {
        return NULL;
    }

    ops = _hash_fetch_ops(
        ZSTR_VAL(_sha256_algo_zstr), ZSTR_LEN(_sha256_algo_zstr));
#else
    ops = php_hash_fetch_ops(_sha256_algo_zstr);
#endif
    if (!ops) {
        mlog(dd_log_debug, "Failed to load sha256 algorithm");
        return NULL;
    }

#if PHP_VERSION_ID < 80000
    context = emalloc(ops->context_size);
#else
    context = php_hash_alloc_context(ops);
#endif

#if PHP_VERSION_ID < 80100
    ops->hash_init(context);
#else
    ops->hash_init(context, NULL);
#endif

    ops->hash_update(
        context, (unsigned char *)ZSTR_VAL(user_id), ZSTR_LEN(user_id));

    digest = zend_string_alloc(ops->digest_size, 0);
    ops->hash_final((unsigned char *)ZSTR_VAL(digest), context);
    efree(context);

#define ANON_PREFIX "anon_"
    // Anonymized IDs start with anon_ followed by the 128 most-significant bits
    // of the sha256
    zend_string *anon_user_id = zend_string_safe_alloc(
        LSTRLEN(ANON_PREFIX) + ops->digest_size, 1, 0, 0);

    // Copy prefix
    memcpy(ZSTR_VAL(anon_user_id), LSTRARG(ANON_PREFIX));

    char *digest_begin = ZSTR_VAL(anon_user_id) + LSTRLEN(ANON_PREFIX);
    php_hash_bin2hex(
        digest_begin, (unsigned char *)ZSTR_VAL(digest), ops->digest_size / 2);
    digest_begin[ops->digest_size] = 0;

    zend_string_release(digest);

    return anon_user_id;
}

user_collection_mode dd_get_user_collection_mode() { return _user_mode; }

zend_string *nonnull dd_get_user_collection_mode_zstr()
{
    if (_user_mode == user_mode_ident) {
        return _user_mode_ident_zstr;
    }

    if (_user_mode == user_mode_anon) {
        return _user_mode_anon_zstr;
    }

    return _user_mode_disabled_zstr;
}
