// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "user_tracking.h"
#include "commands/request_exec.h"
#include "compatibility.h"
#include "ddappsec.h"
#include "dddefs.h"
#include "ddtrace.h"
#include "helper_process.h"
#include "logging.h"
#include "php_compat.h"
#include "php_objects.h"
#include "request_abort.h"
#include "request_lifecycle.h"
#include "string_helpers.h"
#include "tags.h"
#include <Zend/zend_exceptions.h>
#include <Zend/zend_string.h>
#include <ext/hash/php_hash.h>

static THREAD_LOCAL_ON_ZTS user_collection_mode _user_mode = user_mode_disabled;
static THREAD_LOCAL_ON_ZTS user_collection_mode _user_mode_rc =
    user_mode_undefined;

static zend_string *_user_mode_anon_zstr;
static zend_string *_user_mode_ident_zstr;
static zend_string *_user_mode_disabled_zstr;
static zend_string *_sha256_algo_zstr;

static void (*_ddtrace_set_user)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*_ddtrace_v2_track_user_login_success)(
    INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*_ddtrace_v2_track_user_login_failure)(
    INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void _register_test_objects(void);

#if PHP_VERSION_ID < 80000
typedef const php_hash_ops *(*hash_fetch_ops_t)(
    const char *algo, size_t algo_len);
static hash_fetch_ops_t _hash_fetch_ops;
#endif

static PHP_FUNCTION(set_user_wrapper)
{
    if (DDAPPSEC_G(active) || UNEXPECTED(get_global_DD_APPSEC_TESTING())) {
        zend_string *user_id;
        HashTable *metadata = NULL;
        zend_bool propagate = false;
        if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(),
                "S|hb", &user_id, &metadata, &propagate) == SUCCESS) {
            dd_find_and_apply_verdict_for_user(
                user_id, ZSTR_EMPTY_ALLOC(), user_event_none);
        }
    }

    // This shouldn't be necessary, if it is we have a bug
    if (_ddtrace_set_user == NULL) {
        mlog(dd_log_debug, "Invalid DDTrace\\set_user, this shouldn't happen");
        return;
    }

    _ddtrace_set_user(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

static PHP_FUNCTION(v2_track_user_login_success_wrapper)
{
    _ddtrace_v2_track_user_login_success(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    zend_string *login;
    zval *user = NULL;
    zend_array *metadata = NULL;
    zend_string *user_id = NULL;
    if (!DDAPPSEC_G(active) && UNEXPECTED(!get_global_DD_APPSEC_TESTING())) {
        return;
    }
    if (zend_parse_parameters(
            ZEND_NUM_ARGS(), "S|zh", &login, &user, &metadata) == FAILURE) {
        return;
    }
    if (_ddtrace_v2_track_user_login_success == NULL) {
        mlog(dd_log_debug, "Invalid DDTrace\\track_user_login_success, "
                           "this shouldn't happen");
        return;
    }

    if (user != NULL && Z_TYPE_P(user) == IS_STRING) {
        user_id = Z_STR_P(user);
    } else if (user != NULL && Z_TYPE_P(user) == IS_ARRAY) {
        zval *user_id_zv = zend_hash_str_find(Z_ARR_P(user), ZEND_STRL("id"));
        if (user_id_zv == NULL) {
            mlog(dd_log_warning, "Id not found in user object in "
                                 "DDTrace\\ATO\\V2\\track_user_login_success");
            return;
        }
        if (Z_TYPE_P(user_id_zv) != IS_STRING) {
            mlog(dd_log_warning, "Unexpected id type in "
                                 "DDTrace\\ATO\\V2\\track_user_login_success");
            return;
        }
        user_id = Z_STR_P(user_id_zv);
    }

    set_user_event_triggered();
    dd_trace_emit_asm_event();
    dd_tags_set_sampling_priority();
    dd_find_and_apply_verdict_for_user(
        user_id, login, user_event_login_success);
}

static PHP_FUNCTION(v2_track_user_login_failure_wrapper)
{
    _ddtrace_v2_track_user_login_failure(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    zend_string *login = NULL;
    zend_bool exists;
    zend_array *metadata = NULL;

    if (!DDAPPSEC_G(active) && UNEXPECTED(!get_global_DD_APPSEC_TESTING())) {
        return;
    }

    if (zend_parse_parameters(
            ZEND_NUM_ARGS(), "Sb|h", &login, &exists, &metadata) == FAILURE) {
        return;
    }

    if (_ddtrace_v2_track_user_login_failure == NULL) {
        mlog(dd_log_debug, "Invalid DDTrace\\track_user_login_failure, "
                           "this shouldn't happen");
        return;
    }

    set_user_event_triggered();
    dd_trace_emit_asm_event();
    dd_tags_set_sampling_priority();
    dd_find_and_apply_verdict_for_user(
        ZSTR_EMPTY_ALLOC(), login, user_event_login_failure);
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
        (hash_fetch_ops_t)(uintptr_t)dlsym(RTLD_DEFAULT, "php_hash_fetch_ops");
    if (!_hash_fetch_ops) {
        mlog(dd_log_warning, "Failed to load php_hash_fetch_ops: %s",
            dlerror()); // NOLINT(concurrency-mt-unsafe)
    }
#endif

    _register_test_objects();

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

    zend_function *v2_track_user_login_success =
        zend_hash_str_find_ptr(CG(function_table),
            LSTRARG("ddtrace\\ato\\v2\\track_user_login_success"));
    if (v2_track_user_login_success != NULL) {
        _ddtrace_v2_track_user_login_success =
            v2_track_user_login_success->internal_function.handler;
        v2_track_user_login_success->internal_function.handler =
            PHP_FN(v2_track_user_login_success_wrapper);
    } else {
        bool testing = get_global_DD_APPSEC_TESTING();
        if (!testing) {
            // Avoid logging on MINIT during tests
            mlog(dd_log_warning,
                "DDTrace\\ATO\\V2\\track_user_login_success not found");
        }
    }

    zend_function *v2_track_user_login_failure =
        zend_hash_str_find_ptr(CG(function_table),
            LSTRARG("ddtrace\\ato\\v2\\track_user_login_failure"));
    if (v2_track_user_login_failure != NULL) {
        _ddtrace_v2_track_user_login_failure =
            v2_track_user_login_failure->internal_function.handler;
        v2_track_user_login_failure->internal_function.handler =
            PHP_FN(v2_track_user_login_failure_wrapper);
    } else {
        bool testing = get_global_DD_APPSEC_TESTING();
        if (!testing) {
            // Avoid logging on MINIT during tests
            mlog(dd_log_warning,
                "DDTrace\\ATO\\V2\\track_user_login_failure not found");
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

void dd_find_and_apply_verdict_for_user(zend_string *nullable user_id,
    zend_string *nullable user_login, user_event event)
{
    if (!DDAPPSEC_G(active) && UNEXPECTED(!get_global_DD_APPSEC_TESTING())) {
        return;
    }

    dd_conn *conn = dd_helper_mgr_cur_conn();
    if (conn == NULL) {
        mlog(dd_log_debug, "No connection; unable to check user");
        return;
    }

    zval data_zv;
    size_t data_size = 0;
    data_size += user_login != NULL && ZSTR_LEN(user_login) > 0 ? 1 : 0;
    data_size += user_id != NULL && ZSTR_LEN(user_id) > 0 ? 1 : 0;
    data_size += event != user_event_none ? 1 : 0;
    array_init_size(&data_zv, data_size);

    if (event == user_event_login_success) {
        zend_hash_str_add_empty_element(Z_ARRVAL(data_zv),
            LSTRARG("server.business_logic.users.login.success"));
    } else if (event == user_event_login_failure) {
        zend_hash_str_add_empty_element(Z_ARRVAL(data_zv),
            LSTRARG("server.business_logic.users.login.failure"));
    }

    if (user_login != NULL && ZSTR_LEN(user_login) > 0) {
        zval user_login_zv;
        ZVAL_STR_COPY(&user_login_zv, user_login);

        zend_hash_str_add_new(Z_ARRVAL(data_zv), "usr.login",
            sizeof("usr.login") - 1, &user_login_zv);
    }

    if (user_id != NULL && ZSTR_LEN(user_id) > 0) {
        zval user_id_zv;
        ZVAL_STR_COPY(&user_id_zv, user_id);
        zend_hash_str_add_new(
            Z_ARRVAL(data_zv), "usr.id", sizeof("usr.id") - 1, &user_id_zv);
    }

    dd_result res = dd_request_exec(conn, &data_zv, false);
    if (res == dd_network) {
        mlog_g(dd_log_info, "request_exec failed with dd_network; closing "
                            "connection to helper");
        dd_helper_close_conn();
    }

    zval_ptr_dtor(&data_zv);

    if (user_id != NULL && ZSTR_LEN(user_id) > 0) {
        dd_tags_set_event_user_id(user_id);
    }

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

void dd_parse_user_collection_mode_rc(
    const char *nonnull value, size_t value_len)
{
    if (dd_string_equals_lc(value, value_len, ZEND_STRL("undefined"))) {
        _user_mode_rc = user_mode_undefined;
    } else if (dd_string_equals_lc(
                   value, value_len, ZEND_STRL("identification"))) {
        _user_mode_rc = user_mode_ident;
    } else if (dd_string_equals_lc(
                   value, value_len, ZEND_STRL("anonymization"))) {
        _user_mode_rc = user_mode_anon;
    } else { // If the value is disabled or an unknown value, we disable user ID
             // collection
        if (!get_global_DD_APPSEC_TESTING()) {
            mlog_g(dd_log_warning,
                "Unknown or disabled remote config user collection mode: %.*s",
                (int)value_len, value);
        }
        _user_mode_rc = user_mode_disabled;
    }
}

zend_string *nullable dd_user_info_anonymize(zend_string *nonnull user_info)
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
        context, (unsigned char *)ZSTR_VAL(user_info), ZSTR_LEN(user_info));

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

user_collection_mode dd_get_user_collection_mode(void)
{
    return _user_mode_rc != user_mode_undefined ? _user_mode_rc : _user_mode;
}

zend_string *nonnull dd_get_user_collection_mode_zstr(void)
{
    user_collection_mode mode = dd_get_user_collection_mode();

    if (mode == user_mode_ident) {
        return _user_mode_ident_zstr;
    }

    if (mode == user_mode_anon) {
        return _user_mode_anon_zstr;
    }

    return _user_mode_disabled_zstr;
}

PHP_FUNCTION(datadog_appsec_testing_dump_user_collection_mode)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    RETURN_STR(dd_get_user_collection_mode_zstr());
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(dump_user_collection_mode_arginfo, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "dump_user_collection_mode", PHP_FN(datadog_appsec_testing_dump_user_collection_mode), dump_user_collection_mode_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_test_objects(void)
{
    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }
    dd_phpobj_reg_funcs(functions);
}
