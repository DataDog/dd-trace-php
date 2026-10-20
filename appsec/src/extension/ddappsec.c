// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <SAPI.h>
#include <Zend/zend_extensions.h>
#include <ext/standard/info.h>
#include <php.h>

#include <dlfcn.h>
#include <fcntl.h>
#include <string.h>
#include <sys/stat.h>
#include <sys/types.h>

#include <pthread.h>
#include <stdatomic.h>

#include "backtrace.h"
#include "commands/client_init.h"
#include "commands/request_exec.h"
#include "commands_ctx.h"
#include "commands_helpers.h"
#include "configuration.h"
#include "ddappsec.h"
#include "ddappsec_api.h"
#include "ddappsec_arginfo.h"
#include "dddefs.h"
#include "duration_acc.h"
#include "entity_body.h"
#include "helper_process.h"
#include "ip_extraction.h"
#include "logging.h"
#include "msgpack_helpers.h"
#include "network.h"
#include "php_objects.h"
#include "request_abort.h"
#include "request_lifecycle.h"
#include "tags.h"
#include "telemetry.h"
#include "tracer_bridge.h"
#include "user_tracking.h"
#include <ext/compatibility.h>

#include <json/json.h>
#include <zend_string.h>

#if ZTS
static atomic_int _thread_count;
#endif

static void _check_enabled(void);
static void _register_testing_objects(void);

void dd_appsec_ginit(ddappsec_globals *globals)
{
    // Don't log here; the logging subsystem is only initialized in MINIT, and
    // then fully initialized in the first RINIT.
    memset(globals, '\0', sizeof(*globals)); // NOLINT
    globals->to_be_configured = true;
#if ZTS
    atomic_fetch_add(&_thread_count, 1);
    // GSHUTDOWN may run on another thread, which then needs this thread's
    // storage to reach its sidecar transport.
    globals->ts_ls_cache = tsrm_get_ls_cache();
#endif
}

void dd_appsec_gshutdown(ddappsec_globals *globals)
{
    dd_entity_body_gshutdown(globals);
    dd_telemetry_gshutdown(globals);
    dd_helper_gshutdown(globals);

    mlog_g(dd_log_debug, "Finished GSHUTDOWN actions (thread %" PRIxPTR ")",
        (uintptr_t)pthread_self());

    // delay log shutdown until the last possible moment, so that TSRM
    // destructors can run with logging
#if ZTS
    if (atomic_fetch_add(&_thread_count, -1) == 1) {
        dd_log_shutdown();
    }
#else
    dd_log_shutdown();
#endif
}

void dd_appsec_minit_before_config(void) { dd_log_startup_before_cfg(); }

void dd_appsec_minit(int module_number)
{
    dd_phpobj_startup(module_number);
    dd_log_startup_after_cfg();
    dd_config_minit();

    dd_phpobj_reg_funcs(ext_functions);
    _register_testing_objects();

    dd_helper_startup();
    dd_trace_startup();
    dd_req_lifecycle_startup();
    dd_user_tracking_startup();
    dd_request_abort_startup();
    dd_tags_startup();
    dd_duration_startup();
    dd_ip_extraction_startup();
    dd_entity_body_startup();
    dd_backtrace_startup();
    dd_msgpack_helpers_startup();
    dd_telemetry_startup();
}

void dd_appsec_mshutdown(void)
{
    // Say goodbye to the helper while the sidecar connection still exists.
    dd_helper_mshutdown();

    dd_duration_shutdown();
    dd_tags_shutdown();
    dd_request_abort_shutdown();
    dd_user_tracking_shutdown();
    dd_trace_shutdown();
    dd_helper_shutdown();

    dd_phpobj_shutdown();

    // The configuration is about to be destroyed; logging from GSHUTDOWN must
    // not read it anymore.
    dd_log_detach_cfg();
}

void dd_appsec_zend_startup(void) { dd_request_abort_zend_ext_startup(); }

static void _rinit_once(void) { _check_enabled(); }

void dd_appsec_rinit_once(void)
{
    static pthread_once_t _rinit_once_control = PTHREAD_ONCE_INIT;
    pthread_once(&_rinit_once_control, _rinit_once);
}

void dd_appsec_rinit(void)
{
    // Safety precaution
    DDAPPSEC_G(during_request_shutdown) = false;

    dd_appsec_rinit_once();
    _check_enabled();

    if (DDAPPSEC_G(enabled) == APPSEC_FULLY_DISABLED) {
        return;
    }
    DDAPPSEC_G(skip_rshutdown) = false;
    dd_msgpack_helpers_rinit();
    dd_trace_rinit();

    // Waf calls happen here. Not many rinits should go after this line.
    dd_req_lifecycle_rinit(false);

    if (UNEXPECTED(get_global_DD_APPSEC_TESTING())) {
        if (get_global_DD_APPSEC_TESTING_ABORT_RINIT()) {
            const char *pt = SG(request_info).path_translated;
            if (pt && !strstr(pt, "skip.php")) {
                dd_request_abort_static_page(&(struct block_params){0});
            }
        }
    }
}

void dd_appsec_rshutdown_hook(void)
{
    // Here now we have to disconnect from the helper in all the cases but when
    // disabled by config
    if (DDAPPSEC_G(enabled) == APPSEC_FULLY_DISABLED ||
        DDAPPSEC_G(skip_rshutdown)) {
        return;
    }

    DDAPPSEC_G(during_request_shutdown) = true;
    dd_appsec_rshutdown(false);
    DDAPPSEC_G(during_request_shutdown) = false;
}

int dd_appsec_rshutdown(bool ignore_verdict)
{
    dd_req_lifecycle_rshutdown(ignore_verdict, false);
    return SUCCESS;
}

void dd_appsec_minfo(void)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "Application Security", "");
    php_info_print_table_row(2, "State managed by remote config",
        DDAPPSEC_G(enabled) == APPSEC_ENABLED_VIA_REMCFG ? "Yes" : "No");
    php_info_print_table_row(2, "Current state",
        DDAPPSEC_G(active)             ? "Enabled"
        : DDAPPSEC_G(to_be_configured) ? "Not configured"
                                       : "Disabled");
    php_info_print_table_row(
        2, "Connected to helper?", dd_helper_mgr_cur_conn() ? "Yes" : "No");
    php_info_print_table_end();
}

static void _check_enabled(void)
{
    if (DDAPPSEC_G(enabled) != APPSEC_UNSET_STATE) {
        return;
    }

    if (datadog_disable == 1 ||
        (!get_global_DD_APPSEC_TESTING() && !dd_trace_enabled()) ||
        (strcmp(sapi_module.name, "cli") != 0 && sapi_module.phpinfo_as_text)) {
        DDAPPSEC_G(enabled) = APPSEC_FULLY_DISABLED;
        DDAPPSEC_G(active) = false;
        DDAPPSEC_G(to_be_configured) = false;
    } else if (!dd_cfg_enable_via_remcfg()) {
        if (get_global_DD_APPSEC_ENABLED()) {
            DDAPPSEC_G(enabled) = APPSEC_FULLY_ENABLED;
            DDAPPSEC_G(active) = true;
            DDAPPSEC_G(to_be_configured) = false;
        } else {
            DDAPPSEC_G(enabled) = APPSEC_FULLY_DISABLED;
            DDAPPSEC_G(active) = false;
            DDAPPSEC_G(to_be_configured) = false;
        }
    } else {
        DDAPPSEC_G(enabled) = APPSEC_ENABLED_VIA_REMCFG;
        // leave DDAPPSEC_G(active) as is
    };
}

PHP_FUNCTION(datadog_appsec_is_enabled)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    RETURN_BOOL(DDAPPSEC_G(active));
}

static PHP_FUNCTION(datadog_appsec_testing_rinit)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    mlog(dd_log_debug, "Running rinit actions");
    dd_req_lifecycle_rinit(true);
    RETURN_TRUE;
}

static PHP_FUNCTION(datadog_appsec_testing_rshutdown)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    DDAPPSEC_G(during_request_shutdown) = true;
    mlog(dd_log_debug, "Running rshutdown actions");
    dd_req_lifecycle_rshutdown(false, true);
    DDAPPSEC_G(during_request_shutdown) = false;
    RETURN_TRUE;
}
static PHP_FUNCTION(datadog_appsec_testing_helper_mgr_acquire_conn)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    struct req_info ctx = {
        .root_span = dd_trace_get_active_root_span(),
    };
    dd_conn *conn =
        dd_helper_mgr_acquire_conn((client_init_func)dd_client_init, &ctx);
    if (conn) {
        RETURN_TRUE;
    } else {
        RETURN_FALSE;
    }
}

static PHP_FUNCTION(datadog_appsec_testing_stop_for_debugger)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    int fd = open(
        "/tmp/pid", O_CREAT | O_WRONLY | O_TRUNC | O_CLOEXEC, 0600); // NOLINT
    char pid[sizeof("-2147483648")] = "";
    sprintf(pid, "%" PRIi32, (int32_t)getpid()); // NOLINT
    ATTR_UNUSED ssize_t unused_ = write(fd, pid, strlen(pid));
    sleep(6); // NOLINT

    RETURN_TRUE;
}

static PHP_FUNCTION(datadog_appsec_testing_request_exec)
{
    zend_array *data = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "h", &data) != SUCCESS) {
        RETURN_FALSE;
    }

    struct req_info ctx = {
        .root_span = dd_trace_get_active_root_span(),
    };
    dd_conn *conn =
        dd_helper_mgr_acquire_conn((client_init_func)dd_client_init, &ctx);
    if (conn == NULL) {
        mlog_g(dd_log_debug, "No connection; skipping request_exec");
        RETURN_FALSE;
    }

    struct block_params block_params = {0};
    struct req_exec_opts opts = {0};
    if (dd_request_exec(conn, data, &opts, &block_params) != dd_success) {
        RETVAL_FALSE;
    } else {
        RETVAL_TRUE;
    }
    dd_request_abort_destroy_block_params(&block_params);
}

static dd_result _pack_invalid_command(
    mpack_writer_t *nonnull w, ATTR_UNUSED void *nullable ctx)
{
    UNUSED(ctx);
    mpack_start_map(w, 1);
    dd_mpack_write_lstr(w, "foo");
    dd_mpack_write_lstr(w, "bar");
    mpack_finish_map(w);
    return dd_success;
}

static dd_result _process_invalid_response(
    mpack_node_t root, ATTR_UNUSED void *nullable ctx)
{
    UNUSED(root);
    UNUSED(ctx);
    return dd_success;
}

static PHP_FUNCTION(datadog_appsec_testing_send_invalid_command)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    dd_conn *conn = dd_helper_mgr_cur_conn();
    if (conn == NULL) {
        struct req_info ctx = {
            .root_span = dd_trace_get_active_root_span(),
        };
        conn =
            dd_helper_mgr_acquire_conn((client_init_func)dd_client_init, &ctx);
        if (conn == NULL) {
            mlog_g(dd_log_debug,
                "Failed to acquire connection for invalid message test");
            RETURN_FALSE;
        }
    }

    static const dd_command_spec invalid_spec = {
        .name = "invalid_command",
        .name_len = sizeof("invalid_command") - 1,
        .num_args = 1,
        .client_init = false,
        .outgoing_cb = _pack_invalid_command,
        .incoming_cb = _process_invalid_response,
        .config_features_cb = dd_command_process_config_features_unexpected,
    };

    dd_result res = dd_command_exec(conn, &invalid_spec, NULL);
    if (res == dd_success) {
        RETURN_TRUE;
    } else {
        char *error = NULL;
        size_t error_len = spprintf(&error, 0,
            "Failed to send invalid command: %s", dd_result_to_string(res));
        dd_helper_close_conn(res == dd_helper_say_goobye, error, error_len);
        efree(error);
        RETURN_FALSE;
    }
}

PHP_FUNCTION(datadog_appsec_push_addresses)
{
    if (!DDAPPSEC_G(active)) {
        mlog(dd_log_debug, "Trying to access to push_addresses function while "
                           "appsec is disabled");
        RETURN_FALSE;
    }

    zval *addresses;
    zend_string *rasp_rule = NULL;
    zend_string *rule_variant = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a|SS", &addresses, &rasp_rule,
            &rule_variant) == FAILURE) {
        RETURN_FALSE;
    }

    bool is_rasp = rasp_rule != NULL && ZSTR_LEN(rasp_rule) > 0;

    if (is_rasp && !get_global_DD_APPSEC_RASP_ENABLED()) {
        mlog(dd_log_debug, "RASP is not enabled; skipping push_addresses");
        RETURN_FALSE;
    }

    if (!dd_req_lifecycle_is_active()) {
        mlog_g(dd_log_info,
            "Not running inside a tracked request; skipping push_addresses");
        if (is_rasp) {
            dd_telemetry_add_rasp_rule_skipped(rasp_rule, rule_variant);
        }
        RETURN_FALSE;
    }

    dd_conn *conn = dd_helper_mgr_cur_conn();
    if (conn == NULL) {
        mlog_g(dd_log_debug, "No connection; skipping push_addresses");
        RETURN_FALSE;
    }

    struct req_exec_opts opts = {
        .rasp_rule = rasp_rule, .rule_variant = rule_variant};
    struct block_params block_params = {0};
    struct timespec start = dd_monotime_start();
    dd_result res =
        dd_request_exec(conn, Z_ARRVAL_P(addresses), &opts, &block_params);

    if (is_rasp) {
        dd_duration_rasp_ext_account(&start);
    } else {
        dd_duration_waf_ext_account(&start);
    }

    dd_req_lifecycle_abort(REQUEST_STAGE_MID_REQUEST, res, &block_params);
    dd_request_abort_destroy_block_params(&block_params);

    RETURN_TRUE;
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    void_ret_bool_arginfo, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(request_exec_arginfo, 0, 1, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO(0, data, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

// clang-format off
// Available under either DD_APPSEC_TESTING or DD_APPSEC_TESTING_INVALID_COMMAND
static const zend_function_entry testing_request_control_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "rinit", PHP_FN(datadog_appsec_testing_rinit), void_ret_bool_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "rshutdown", PHP_FN(datadog_appsec_testing_rshutdown), void_ret_bool_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "request_exec", PHP_FN(datadog_appsec_testing_request_exec), request_exec_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
static const zend_function_entry testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "helper_mgr_acquire_conn", PHP_FN(datadog_appsec_testing_helper_mgr_acquire_conn), void_ret_bool_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "stop_for_debugger", PHP_FN(datadog_appsec_testing_stop_for_debugger), void_ret_bool_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
static const zend_function_entry testing_invalid_command_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "send_invalid_msg", PHP_FN(datadog_appsec_testing_send_invalid_command), void_ret_bool_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects(void)
{
    bool invalid_command = get_global_DD_APPSEC_TESTING_INVALID_COMMAND();
    bool full_testing = get_global_DD_APPSEC_TESTING();

    if (invalid_command) {
        dd_phpobj_reg_funcs(testing_invalid_command_functions);
    }

    if (invalid_command || full_testing) {
        dd_phpobj_reg_funcs(testing_request_control_functions);
    }

    if (full_testing) {
        dd_phpobj_reg_funcs(testing_functions);
    }
}
