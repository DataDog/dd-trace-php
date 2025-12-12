// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// NOLINTNEXTLINE(misc-header-include-cycle)
#include <components-rs/ddtrace.h>
#include <php.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <sys/mman.h>

#define HELPER_PROCESS_C_INCLUDES
#include "compatibility.h"
#include "configuration.h"
#include "ddappsec.h"
#include "dddefs.h"
#include "helper_process.h"
#include "logging.h"
#include "network.h"
#include "php_compat.h"
#include "php_objects.h"
#include "version.h"

#define MAX_WAIT_TIME_MS (1ULL << 55)
typedef struct _dd_helper_shared_state {
    uint8_t failed_count : 7;
    bool try_in_progress : 1; // only for log messages
    uint64_t suppressed_until_ms : 56;
} dd_helper_shared_state;
#define MAX_FAILED_COUNT ((uint8_t)((1U << 7) - 1))
#define MAX_SUPPRESSION_TIME_MS ((1ULL << 12) * 1000ULL) // a little over 1 hour
_Static_assert(sizeof(dd_helper_shared_state) == sizeof(uint64_t),
    "dd_helper_shared_state should be 8 bytes");

typedef struct _dd_helper_mgr {
    dd_conn conn;

    bool connected_this_req;
    dd_helper_shared_state hss;

    char *nonnull socket_path; // if abstract, starts with @
    char *nonnull lock_path;   // set, but not used with abstract ns sockets
} dd_helper_mgr;

static _Atomic(dd_helper_shared_state) *_shared_state;

static THREAD_LOCAL_ON_ZTS dd_helper_mgr _mgr;

static const double _backoff_initial = 3.0;
static const double _backoff_base = 2.0;
// max retry will be 3 * 2^10 =~ 51 mins */
static const double _backoff_max_exponent = 10.0;

static const int timeout_send = 500;
static const int timeout_recv_initial = 1250;
static const int timeout_recv_subseq = 750;

#define DD_PATH_FORMAT "%s%sddappsec_" PHP_DDAPPSEC_VERSION "_%u"
#define DD_SOCK_PATH_FORMAT DD_PATH_FORMAT ".sock"
#define DD_LOCK_PATH_FORMAT DD_PATH_FORMAT ".lock"

#ifdef TESTING
static void _register_testing_objects(void);
#endif

static void _read_settings(void);
static bool _skip_connecting(dd_helper_shared_state *nonnull s);
static bool _try_lock_shared_state(dd_helper_shared_state *nonnull s);
static void _inc_failed_counter(dd_helper_shared_state *nonnull s);
static void _release_shared_state_lock(dd_helper_shared_state *nonnull s);
static void _maybe_reset_failed_counter(void);

void dd_helper_startup(void)
{
    _shared_state = mmap(NULL, sizeof(dd_helper_shared_state),
        PROT_READ | PROT_WRITE, MAP_SHARED | MAP_ANONYMOUS, -1, 0);

    if (_shared_state == MAP_FAILED) {
        _shared_state = NULL;
        mlog_err(dd_log_error, "Failed to mmap shared state");
    }

#ifdef TESTING
    _register_testing_objects();
#endif
}

void dd_helper_shutdown(void) {}

void dd_helper_gshutdown(void)
{
    pefree(_mgr.socket_path, 1);
    pefree(_mgr.lock_path, 1);
    if (_shared_state) {
        munmap(_shared_state, sizeof(dd_helper_shared_state));
    }
}

void dd_helper_rshutdown(void)
{
    _maybe_reset_failed_counter();

    _mgr.connected_this_req = false;
    _mgr.hss = (typeof(_mgr.hss)){0};
}

dd_conn *nullable dd_helper_mgr_acquire_conn(
    client_init_func nonnull init_func, void *unspecnull ctx)
{
    dd_conn *conn = &_mgr.conn;
    if (dd_conn_connected(conn)) {
        return conn;
    }

    if (_skip_connecting(&_mgr.hss)) {
        return NULL;
    }

    _read_settings();

    if (!_try_lock_shared_state(&_mgr.hss)) {
        return NULL;
    }

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
    _release_shared_state_lock(&_mgr.hss);

    return conn;

error:
    _inc_failed_counter(&_mgr.hss);
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
bool dd_on_runtime_path_update(zval *nullable old_val, zval *nonnull new_val,
    zend_string *nullable new_str)
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

static void _read_settings(void)
{
    if (_mgr.socket_path) {
        return;
    }

    zval runtime_path;
    ZVAL_STR(&runtime_path, get_DD_APPSEC_HELPER_RUNTIME_PATH());
    dd_on_runtime_path_update(NULL, &runtime_path, NULL);
}

__attribute__((visibility("default"))) bool dd_appsec_maybe_enable_helper(
    sidecar_enable_appsec_t nonnull enable_appsec,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    bool *nonnull appsec_activation, bool *nonnull appsec_conf)
{
    dd_appsec_rinit_once();

    if (DDAPPSEC_G(enabled) == APPSEC_FULLY_DISABLED ||
        get_global_DD_APPSEC_TESTING()) {
        *appsec_activation = false;
        *appsec_conf = false;
        return false;
    }

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

    *appsec_activation = DDAPPSEC_G(enabled) == APPSEC_ENABLED_VIA_REMCFG;
    // only enable ASM / ASM_DD / ASM_DATA if no rules file is specified
    *appsec_conf = get_global_DD_APPSEC_RULES()->len == 0;

    return true;
}

void dd_helper_close_conn(void)
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
    if (_mgr.connected_this_req && _shared_state) {
        mlog(dd_log_debug, "Connection was closed on the same request as it "
                           "opened. Incrementing backoff counter");

        _inc_failed_counter(&_mgr.hss);
    }
}

static uint64_t _gettime_56bit_ms(void)
{
    struct timespec cur_time;
    if (clock_gettime(CLOCK_MONOTONIC, &cur_time) == -1) {
        mlog_err(dd_log_warning, "Call to clock_gettime() failed");
        return 0;
    }

#define NS_PER_MS 1000000ULL
#define MS_PER_SEC 1000ULL
#define MASK_56_BITS ((1ULL << 56) - 1)

    uint64_t ms_in_sec = (uint64_t)cur_time.tv_nsec / NS_PER_MS;
    uint64_t total_ms = ((uint64_t)cur_time.tv_sec * MS_PER_SEC) + ms_in_sec;

    return total_ms & MASK_56_BITS;
}

// returns true if an attempt to connect should not be made yet
static bool _skip_connecting(dd_helper_shared_state *nonnull s)
{
    if (!_shared_state) {
        return false;
    }

    *s = atomic_load_explicit(_shared_state, memory_order_relaxed);

    if (!s->suppressed_until_ms) {
        return false;
    }

    uint64_t cur_time = _gettime_56bit_ms();
    if (cur_time == 0) {
        return false;
    }

    uint64_t time_delta_ms = (s->suppressed_until_ms - cur_time) & MASK_56_BITS;
    // if cur_time > suppressed_until, then the suppression has expired
    // and the value wraps around, the condition becoming false
    if (time_delta_ms < MAX_SUPPRESSION_TIME_MS) {
        if (s->try_in_progress) {
            mlog(dd_log_debug, "A connection attempt after a failure "
                               "is already in progress in another PHP worker");
        } else {
            mlog(dd_log_debug, "Next connect retry is not due yet");
        }
        return true;
    }

    mlog(dd_log_debug, "Backoff time existed, but has expired");
    return false;
}

static bool _try_lock_shared_state(dd_helper_shared_state *nonnull s)
{
    // with no failures, every process should try to connect
    if (s->failed_count == 0) {
        return true;
    }

    // lock for up to 3 seconds
    // we don't use try_in_progress to lock in case this process is killed
    // or otherwise dies
#define MAX_LOCK_TIME_MS 3000

    uint64_t cur_time_ms = _gettime_56bit_ms();
    uint64_t lock_time = cur_time_ms + MAX_LOCK_TIME_MS;

    dd_helper_shared_state desired_state = {
        .suppressed_until_ms = lock_time,
        .try_in_progress = true,
        .failed_count = s->failed_count,
    };

    while (!atomic_compare_exchange_strong_explicit(_shared_state, s,
        desired_state, memory_order_relaxed, memory_order_relaxed)) {
        uint64_t time_delta_ms =
            (s->suppressed_until_ms - cur_time_ms) & MASK_56_BITS;
        if (time_delta_ms < MAX_SUPPRESSION_TIME_MS) {
            mlog(dd_log_debug, "Connecting was suppressed in the meantime");
            return false;
        }
        if (s->failed_count == 0) {
            return true;
        }

        desired_state.failed_count = s->failed_count;
        // in theory, we could update suppressed_until_ms, but the 3 seconds
        // give enough margin for this to loop a few times and still fully
        // make our connection attempt
    }

    *s = desired_state;
    return true;
}

static void _inc_failed_counter(dd_helper_shared_state *nonnull s)
{
    if (!_shared_state) {
        return;
    }

    unsigned new_failed_count = s->failed_count < MAX_FAILED_COUNT
                                    ? s->failed_count + 1U
                                    : MAX_FAILED_COUNT;

    double wait_s =
        _backoff_initial *
        pow(_backoff_base, MIN((new_failed_count - 1), _backoff_max_exponent));

    mlog(dd_log_debug,
        "Failed counter is to be set to %u and wait for %f seconds",
        new_failed_count, wait_s);

    uint64_t new_suppressed_until_ms =
        // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
        _gettime_56bit_ms() + (uint64_t)(wait_s * 1000ULL);

    dd_helper_shared_state new_state = {
        .try_in_progress = false,
        .failed_count = new_failed_count,
        .suppressed_until_ms = new_suppressed_until_ms,
    };

    // we can call this function without holding the 3 s lock:
    // * if failed count is 0
    // * or if only failed mid-request on the connecting request

    // The interim write might've been a failure, in which case our write would
    // likely be duplicative (we'd have to check the suppression time),
    // it might've been a sucess (failed_count = 0), in which case we could
    // register a new failure with failed_count == 1, or it could be a simple
    // write for a lock, in which case we arguably should let the new connecting
    // process register success/failure.
    // But let's keep it simple, and just give up on our update in all cases
    if (!atomic_compare_exchange_strong_explicit(_shared_state, s, new_state,
            memory_order_relaxed, memory_order_relaxed)) {
        mlog(dd_log_debug, "Failed to update shared state: concurrent update");
    } else {
        mlog(dd_log_debug, "Successfully updated failed counter/wait time");
    }
}

static void _release_shared_state_lock(dd_helper_shared_state *nonnull s)
{
    if (!_shared_state) {
        return;
    }

    // if failed is 0, we did not lock, so there is nothing to reset
    if (s->failed_count == 0) {
        return;
    }

    *s = (dd_helper_shared_state){
        // save the failed count, because we may still fail during this
        // request, which will count as a connection failure
        // This request may be very long though.
        // So we have a compromise: in this interim where we connected but
        // things may still fail during the request, we give up the lock;
        // still, because failed_count is not 0, only one process may attempt to
        // connect at a time.
        .failed_count = s->failed_count,
    };
    // we hold exclusivity for up to 3 seconds; write unconditionally
    atomic_store_explicit(_shared_state, *s, memory_order_relaxed);
    mlog(dd_log_debug, "Released connection lock; other processes can connect "
                       "(though no more than one at once)");
}

static void _maybe_reset_failed_counter(void)
{
    if (_shared_state && _mgr.connected_this_req && _mgr.hss.failed_count > 0 &&
        dd_conn_connected(&_mgr.conn)) {
        // we can reset the failed counter because we had a full request
        // processed successfully
        dd_helper_shared_state new_state = {
            .failed_count = 0,
            .suppressed_until_ms = 0,
            .try_in_progress = false,
        };

        bool res = atomic_compare_exchange_strong_explicit(_shared_state,
            &_mgr.hss, new_state, memory_order_relaxed, memory_order_relaxed);
        if (!res) {
            mlog(
                dd_log_debug, "Failed to reset retry state: concurrent update");
        }
    }
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

    dd_helper_shared_state s =
        atomic_load_explicit(_shared_state, memory_order_relaxed);

    add_assoc_long_ex(
        return_value, ZEND_STRL("failed_count"), (zend_long)s.failed_count);
    add_assoc_double_ex(
        return_value, ZEND_STRL("next_retry"), (double)s.suppressed_until_ms);
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
    ZEND_RAW_FENTRY(DD_TESTING_NS "set_helper_path", PHP_FN(datadog_appsec_testing_set_helper_path), set_string_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "is_connected_to_helper", PHP_FN(datadog_appsec_testing_is_connected_to_helper), void_ret_bool_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "backoff_status", PHP_FN(datadog_appsec_testing_backoff_status), void_ret_array_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects(void)
{
    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }

    dd_phpobj_reg_funcs(functions);
}
#endif
