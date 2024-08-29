// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// NOLINTNEXTLINE(misc-header-include-cycle)
#include <components-rs/ddtrace.h>
#include <php.h>
#include <stdbool.h>

#define HELPER_PROCESS_C_INCLUDES
#include "configuration.h"
#include "ddappsec.h"
#include "dddefs.h"
#include "helper_process.h"
#include "logging.h"
#include "network.h"
#include "php_compat.h"
#include "php_objects.h"

typedef struct _dd_helper_mgr {
    dd_conn conn;

    struct timespec next_retry;
    uint16_t failed_count;
    bool connected_this_req;

    pid_t pid;
    char *nonnull socket_path;
    char *nonnull lock_path;
} dd_helper_mgr;

static THREAD_LOCAL_ON_ZTS dd_helper_mgr _mgr;

static const double _backoff_initial = 3.0;
static const double _backoff_base = 2.0;
// max retry will be 3 * 2^10 =~ 51 mins */
static const double _backoff_max_exponent = 10.0;

static const int timeout_send = 500;
static const int timeout_recv_initial = 7500;
static const int timeout_recv_subseq = 2000;

#define DD_PATH_FORMAT "%s%sddappsec_" PHP_DDAPPSEC_VERSION "_%u"
#define DD_SOCK_PATH_FORMAT DD_PATH_FORMAT ".sock"
#define DD_LOCK_PATH_FORMAT DD_PATH_FORMAT ".lock"

#ifdef TESTING
static void _register_testing_objects(void);
#endif

static void _read_settings(void);
static bool _wait_for_next_retry(void);
static void _inc_failed_counter(void);
static void _reset_retry_state(void);

void dd_helper_startup(void)
{
#ifdef TESTING
    _register_testing_objects();
#endif
}

void dd_helper_shutdown(void) {}

void dd_helper_gshutdown()
{
    pefree(_mgr.socket_path, 1);
    pefree(_mgr.lock_path, 1);
}

void dd_helper_rshutdown() { _mgr.connected_this_req = false; }

dd_conn *nullable dd_helper_mgr_acquire_conn(
    client_init_func nonnull init_func, void *unspecnull ctx)
{
    dd_conn *conn = &_mgr.conn;
    if (dd_conn_connected(conn)) {
        return conn;
    }
    if (_wait_for_next_retry()) {
        return NULL;
    }

    _read_settings();

    int res = dd_conn_init(conn, _mgr.socket_path, strlen(_mgr.socket_path));

    if (res) {
        // connection failure
        mlog(dd_log_warning, "Connection to helper failed (socket: %s): %s",
            _mgr.socket_path, dd_result_to_string(res));
        goto error;
    }

    // else we have a connection. Set timeouts and test it
    dd_conn_set_timeout(conn, comm_type_send, timeout_send);
    dd_conn_set_timeout(conn, comm_type_recv, timeout_recv_initial);

    res = init_func(conn, ctx);
    if (res) {
        mlog_g(dd_log_warning, "Initial exchange with helper failed; "
                               "abandoning the connection");
        dd_conn_destroy(conn);
        goto error;
    }

    dd_conn_set_timeout(&_mgr.conn, comm_type_recv, timeout_recv_subseq);

    mlog(dd_log_debug, "returning fresh connection");

    _mgr.connected_this_req = true;
    _reset_retry_state();

    return conn;

error:
    _inc_failed_counter();
    return NULL;
}

dd_conn *nullable dd_helper_mgr_cur_conn(void)
{
    dd_conn *conn = &_mgr.conn;
    if (dd_conn_connected(conn)) {
        return conn;
    }
    return NULL;
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
bool dd_on_runtime_path_update(zval *nullable old_val, zval *nonnull new_val, zend_string *nullable new_str)
{
    UNUSED(old_val);
    UNUSED(new_str);

    uid_t uid = getuid();
    char *base = Z_STRVAL_P(new_val);
    size_t base_len = Z_STRLEN_P(new_val);
    char *separator = base[base_len - 1] != '/' ? "/" : "";

    size_t sock_name_len =
        snprintf(NULL, 0, DD_SOCK_PATH_FORMAT, base, separator, uid);
    char *sock_name = safe_pemalloc(sock_name_len, sizeof(char), 1, 1);
    snprintf(sock_name, sock_name_len + 1, DD_SOCK_PATH_FORMAT, base, separator,
        uid);
    pefree(_mgr.socket_path, 1);
    _mgr.socket_path = sock_name;

    size_t lock_name_len =
        snprintf(NULL, 0, DD_LOCK_PATH_FORMAT, base, separator, uid);
    char *lock_name = safe_pemalloc(lock_name_len, sizeof(char), 1, 1);
    snprintf(lock_name, lock_name_len + 1, DD_LOCK_PATH_FORMAT, base, separator,
        uid);
    pefree(_mgr.lock_path, 1);
    _mgr.lock_path = lock_name;

    return true;
}

static inline ddog_CharSlice to_char_slice(zend_string *zs)
{
    return (ddog_CharSlice){.len = ZSTR_LEN(zs), .ptr = ZSTR_VAL(zs)};
}

static void _read_settings()
{
    if (_mgr.socket_path) {
        return;
    }

    dd_appsec_rinit_once();

    zval runtime_path;
    ZVAL_STR(&runtime_path, get_DD_APPSEC_HELPER_RUNTIME_PATH());
    dd_on_runtime_path_update(NULL, &runtime_path, NULL);
}

__attribute__((visibility("default"))) void dd_appsec_maybe_enable_helper(
    sidecar_enable_appsec_t nonnull enable_appsec)
{
    _read_settings();

    ddog_CharSlice helper_path = to_char_slice(get_DD_APPSEC_HELPER_PATH());
    mlog(dd_log_debug, "Helper path is %.*s", (int)helper_path.len,
        helper_path.ptr);
    ddog_CharSlice socket_path = {_mgr.socket_path, strlen(_mgr.socket_path)};
    ddog_CharSlice lock_path = {_mgr.lock_path, strlen(_mgr.lock_path)};
    ddog_CharSlice log_path =
        to_char_slice(get_global_DD_APPSEC_HELPER_LOG_FILE());
    ddog_CharSlice log_level =
        to_char_slice(get_global_DD_APPSEC_HELPER_LOG_LEVEL());

    enable_appsec(helper_path, socket_path, lock_path, log_path, log_level);
}

void dd_helper_close_conn()
{
    if (!dd_conn_connected(&_mgr.conn)) {
        mlog(dd_log_debug, "Not connected; nothing to do");
        return;
    }

    int res = dd_conn_destroy(&_mgr.conn);
    if (res == -1) {
        mlog_err(dd_log_warning, "Error closing connection to helper");
    }

    /* we treat closing the connection on the request it was opened a failure
     * for the purposes of the connection backoff */
    if (_mgr.connected_this_req) {
        mlog(dd_log_debug, "Connection was closed on the same request as it "
                           "opened. Incrementing backoff counter");
        _inc_failed_counter();
    }
}

// returns true if an attempt to connectt should not be made yet
static bool _wait_for_next_retry()
{
    if (!_mgr.next_retry.tv_sec) {
        return false;
    }

    struct timespec cur_time;
    if (clock_gettime(CLOCK_MONOTONIC, &cur_time) == -1) {
        mlog_err(dd_log_warning, "Call to clock_gettime() failed");
        return false;
    }
    if (cur_time.tv_sec < _mgr.next_retry.tv_sec ||
        (cur_time.tv_sec == _mgr.next_retry.tv_sec &&
            cur_time.tv_nsec < _mgr.next_retry.tv_nsec)) {
        mlog(dd_log_debug, "Next connect retry is not due yet");
        return true;
    }

    mlog(dd_log_debug, "Backoff time existed, but has expired");
    return false;
}

static void _inc_failed_counter()
{
    if (_mgr.failed_count != UINT16_MAX) {
        _mgr.failed_count++;
    }
    mlog(dd_log_debug, "Failed counter is now at %u", _mgr.failed_count);

    struct timespec cur_time;
    int res = clock_gettime(CLOCK_MONOTONIC, &cur_time);
    if (res == -1) {
        mlog_err(dd_log_warning, "Call to clock_gettime() failed");
        _mgr.next_retry = (struct timespec){0};
        return;
    }

    double wait =
        _backoff_initial *
        pow(_backoff_base, MIN((_mgr.failed_count - 1), _backoff_max_exponent));

    _mgr.next_retry = cur_time;
    _mgr.next_retry.tv_sec += (time_t)wait;
}

static void _reset_retry_state()
{
    _mgr.failed_count = 0;
    _mgr.next_retry = (struct timespec){0};
}

#ifdef TESTING
static PHP_FUNCTION(datadog_appsec_testing_set_helper_path)
{
    zend_string *zstr;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &zstr) == FAILURE) {
        RETURN_FALSE;
    }

    zend_alter_ini_entry(
        zai_config_memoized_entries[DDAPPSEC_CONFIG_DD_APPSEC_HELPER_PATH]
            .ini_entries[0]
            ->name,
        zstr, ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME);
}

static PHP_FUNCTION(datadog_appsec_testing_is_connected_to_helper)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    if (dd_conn_connected(&_mgr.conn)) {
        RETURN_TRUE;
    } else {
        RETURN_FALSE;
    }
}

#    define TEN_E9_D 1000000000.0
static PHP_FUNCTION(datadog_appsec_testing_backoff_status)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    array_init_size(return_value, 2);

    add_assoc_long_ex(
        return_value, ZEND_STRL("failed_count"), (zend_long)_mgr.failed_count);
    add_assoc_double_ex(return_value, ZEND_STRL("next_retry"),
        (double)_mgr.next_retry.tv_sec +
            (double)_mgr.next_retry.tv_nsec / TEN_E9_D);
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(set_string_arginfo, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, value, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_bool_arginfo, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_array_arginfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "set_helper_path", PHP_FN(datadog_appsec_testing_set_helper_path), set_string_arginfo, 0)
    ZEND_RAW_FENTRY(DD_TESTING_NS "is_connected_to_helper", PHP_FN(datadog_appsec_testing_is_connected_to_helper), void_ret_bool_arginfo, 0)
    ZEND_RAW_FENTRY(DD_TESTING_NS "backoff_status", PHP_FN(datadog_appsec_testing_backoff_status), void_ret_array_arginfo, 0)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects()
{
    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }

    dd_phpobj_reg_funcs(functions);
}
#endif
