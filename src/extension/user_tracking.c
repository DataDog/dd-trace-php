// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "user_tracking.h"
#include "commands/request_exec.h"
#include "ddappsec.h"
#include "helper_process.h"
#include "logging.h"
#include "php_compat.h"
#include "src/extension/request_abort.h"
#include "string_helpers.h"

static void (*_ddtrace_set_user)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

static PHP_FUNCTION(set_user_wrapper)
{
    zend_string *user_id = NULL;
    HashTable *metadata = NULL;
    zend_bool propagate = false;
    if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(),
            "S|hb", &user_id, &metadata, &propagate) == SUCCESS) {
        if (user_id != NULL) {
            dd_find_and_apply_verdict_for_user(user_id);
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

    dd_result res = dd_request_exec(conn, &data_zv);
    zval_ptr_dtor(&data_zv);

    if (res == dd_should_block) {
        dd_request_abort_static_page();
    } else if (res == dd_should_redirect) {
        dd_request_abort_redirect();
    }
}
