// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// NOLINTNEXTLINE(misc-header-include-cycle)
#include <php.h>
#include <php_output.h>
#include <spprintf.h>
#include <stdatomic.h>
#include <stdbool.h>

#include <fcntl.h>
#include <signal.h>
#include <sys/file.h>
#include <sys/resource.h>
#include <sys/time.h>
#include <sys/un.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>
#include <zend_API.h>
#include <zend_alloc.h>
#include <zend_hash.h>
#include <zend_string.h>
#include <zend_types.h>

#define HELPER_PROCESS_C_INCLUDES
#include "configuration.h"
#include "ddappsec.h"
#include "dddefs.h"
#include "helper_process.h"
#include "logging.h"
#include "network.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "php_objects.h"
#include "string_helpers.h"
#include "version.h"

typedef struct _dd_helper_mgr {
    dd_conn conn;

    struct timespec next_retry;
    uint16_t failed_count;
    bool connected_this_req;
    bool launched_this_req;
    pid_t pid;
    char *nonnull socket_path;
    char *nonnull lock_path;
} dd_helper_mgr;

static atomic_int _launch_failure_fd_lock;

static THREAD_LOCAL_ON_ZTS dd_helper_mgr _mgr;

static const double _backoff_initial = 3.0;
static const double _backoff_base = 2.0;
// max retry will be 3 * 2^10 =~ 51 mins */
static const double _backoff_max_exponent = 10.0;

static const int timeout_send = 500;
static const int timeout_recv_initial = 7500;
static const int timeout_recv_subseq = 2000;

#define DD_PATH_FORMAT "%s%sddappsec_" PHP_DDAPPSEC_VERSION "_%u.%u"
#define DD_SOCK_PATH_FORMAT DD_PATH_FORMAT ".sock"
#define DD_LOCK_PATH_FORMAT DD_PATH_FORMAT ".lock"

#ifndef CLOCK_MONOTONIC_COARSE
#    define CLOCK_MONOTONIC_COARSE CLOCK_MONOTONIC
#endif
#ifdef TESTING
static void _register_testing_objects(void);
#endif

void dd_helper_startup(void)
{
    atomic_store(&_launch_failure_fd_lock, -1);
#ifdef TESTING
    _register_testing_objects();
#endif
}

void dd_helper_shutdown(void)
{
    int failure_lock_fd = atomic_load(&_launch_failure_fd_lock);
    if (failure_lock_fd != -1) {
        // no need for compare and exchange, dd_helper_shutdown
        // is called from MSHUTDOWN
        atomic_store(&_launch_failure_fd_lock, -1);
        close(failure_lock_fd);
    }
}

void dd_helper_gshutdown()
{
    pefree(_mgr.socket_path, 1);
    pefree(_mgr.lock_path, 1);
}

void dd_helper_rshutdown()
{
    _mgr.connected_this_req = false;
    _mgr.launched_this_req = false;
}

static bool _wait_for_next_retry(void);
static void _inc_failed_counter(void);
static void _prevent_launch_attempts(int lock_fd);
static bool /* retry */ _maybe_launch_helper(void);
static void _connection_succeeded(void);
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
    zval runtime_path;
    ZVAL_STR(&runtime_path, get_DD_APPSEC_HELPER_RUNTIME_PATH());
    dd_on_runtime_path_update(NULL, &runtime_path, NULL);

    bool retry = false;
    for (int attempt = 0;; attempt++) {
        int res =
            dd_conn_init(conn, _mgr.socket_path, strlen(_mgr.socket_path));

        if (res) {
            // connection failure
            if (attempt == 0) {
                // on first attempt, try to launch the helper
                retry = _maybe_launch_helper();
                if (retry) {
                    continue;
                }
                // no retry
                mlog(dd_log_warning,
                    "Connection to helper failed and we are not going to "
                    "attempt to launch it: %s",
                    dd_result_to_string(res));
                goto error;
            } else { // attempt == 1
                // 2nd connection attempt failed
                // after apparently succeeding in launching the helper
                mlog(dd_log_warning,
                    "Connection to helper failed; we tried to launch it "
                    "and connect again, only to fail again: %s",
                    dd_result_to_string(res));
                _prevent_launch_attempts(-1);
                goto error;
            }
        }

        // else we have a connection. Set timeouts and test it
        dd_conn_set_timeout(conn, comm_type_send, timeout_send);
        dd_conn_set_timeout(conn, comm_type_recv, timeout_recv_initial);

        res = init_func(conn, ctx);
        if (res) {
            mlog_g(dd_log_warning, "Initial exchange with helper failed; "
                                   "abandoning the connection");
            dd_conn_destroy(conn);
            if (attempt == 1) {
                _prevent_launch_attempts(-1);
            }
            goto error;
        } else {
            dd_conn_set_timeout(
                &_mgr.conn, comm_type_recv, timeout_recv_subseq);
        }

        // else success
        break;
    }

    _connection_succeeded();

    mlog(dd_log_debug, "returning fresh connection");
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
    gid_t gid = getgid();
    char *base = Z_STRVAL_P(new_val);
    size_t base_len = Z_STRLEN_P(new_val);
    char *separator = base[base_len - 1] != '/' ? "/" : "";

    size_t sock_name_len =
        snprintf(NULL, 0, DD_SOCK_PATH_FORMAT, base, separator, uid, gid);
    char *sock_name = safe_pemalloc(sock_name_len, sizeof(char), 1, 1);
    snprintf(sock_name, sock_name_len + 1, DD_SOCK_PATH_FORMAT, base, separator,
        uid, gid);
    pefree(_mgr.socket_path, 1);
    _mgr.socket_path = sock_name;

    size_t lock_name_len =
        snprintf(NULL, 0, DD_LOCK_PATH_FORMAT, base, separator, uid, gid);
    char *lock_name = safe_pemalloc(lock_name_len, sizeof(char), 1, 1);
    snprintf(lock_name, lock_name_len + 1, DD_LOCK_PATH_FORMAT, base, separator,
        uid, gid);
    pefree(_mgr.lock_path, 1);
    _mgr.lock_path = lock_name;

    return true;
}

// returns true if an attempt to connectt should not be made yet
static bool _wait_for_next_retry()
{
    if (!_mgr.next_retry.tv_sec) {
        return false;
    }

    struct timespec cur_time;
    if (clock_gettime(CLOCK_MONOTONIC_COARSE, &cur_time) == -1) {
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

static void _connection_succeeded()
{
    _mgr.connected_this_req = true;
    _mgr.failed_count = 0;
    _mgr.next_retry = (struct timespec){0};
}

static int /* fd */ _acquire_lock(void);
static dd_result _launch_helper_daemon(int lock_fd);
static bool _maybe_launch_helper()
{
    if (!get_global_DD_APPSEC_HELPER_LAUNCH()) {
        mlog(dd_log_debug, "Will not try to launch daemon due to ini "
                           "datadog.appsec.launch_helper");
        return false;
    }

    int lock_fd = _acquire_lock();
    if (lock_fd == -1) {
        mlog(dd_log_info,
            "Could not acquire exclusive lock for launching the daemon");
        return false;
    }

    dd_result res = _launch_helper_daemon(lock_fd);
    if (res) {
        // if this fails, we don't want this worker or any other
        // also trying to start the helper, so we hold on to the lock
        _prevent_launch_attempts(lock_fd);
        return false;
    }

    // however, if we were successful, we let go of the lock and let
    // the helper keep its copy of the file descriptor. If the helper dies,
    // the lock will be released and we can try launching it again
    UNUSED(close(lock_fd));

    _mgr.launched_this_req = true;

    mlog(dd_log_info,
        "Apparently successful launch of the helper; will try reconnecting");

    return true;
}

static void _inc_failed_counter()
{
    if (_mgr.failed_count != UINT16_MAX) {
        _mgr.failed_count++;
    }
    mlog(dd_log_debug, "Failed counter is now at %u", _mgr.failed_count);

    struct timespec cur_time;
    int res = clock_gettime(CLOCK_MONOTONIC_COARSE, &cur_time);
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

static void _prevent_launch_attempts(int lock_fd /* -1 to acquire it */)
{
    if (lock_fd == -1) {
        lock_fd = _acquire_lock();
        if (lock_fd == -1) {
            mlog(dd_log_info,
                "Could not acquire exclusive lock to prevent helper "
                "launch attempts");
            return;
        }
    }

    mlog(dd_log_warning, "holding the exclusive lock indefinitely to "
                         "prevent further attempts to start the helper");
    bool success = atomic_compare_exchange_strong(
        &_launch_failure_fd_lock, &(int){-1}, lock_fd);
    if (!success) {
        mlog(dd_log_error, "failure to set _launch_failure_fd_lock. Bug");
        UNUSED(close(lock_fd));
    }
}

static int /* fd */ _acquire_lock()
{
    // open file descriptor
    const char *lock_file = _mgr.lock_path;
    int fd = open(lock_file, O_CREAT | O_NOFOLLOW | O_RDONLY, 0600); // NOLINT
    if (fd == -1) {
        mlog_err(dd_log_warning, "Could not open lock file %s", lock_file);
        return -1;
    }

    // acquire lock
    int res = flock(fd, LOCK_EX | LOCK_NB);
    if (res == -1) {
        if (errno == EWOULDBLOCK) {
            mlog_g(dd_log_info,
                "The helper lock on %s is already being held; "
                "could not get exclusive lock",
                lock_file);
        } else {
            mlog_err(dd_log_warning, "Failed getting a hold of a lock on %s",
                lock_file);
        }
        res = close(fd);
        if (res == -1) {
            mlog_err(dd_log_warning, "Call to close() failed");
        }
        return -1;
    }

    mlog_g(
        dd_log_debug, "Got exclusive lock on file %s, fd is %d", lock_file, fd);

    return fd;
}

static int /* fd */ _open_socket_for_helper(void);
static char **nullable _split_params(
    const char *exe, const char *orig_params_str);
static dd_result _wait_for_intermediate_process(pid_t pid);
static void _close_file_descriptors(int log_fd, int lock_fd, int sock_fd);
static bool _reset_signals_state(int log_fd);
static ATTR_NO_RETURN void _continue_in_intermediate_process(
    int log_fd, int lock_fd, int sock_fd, const char *executable, char **argv);
static dd_result _launch_helper_daemon(int lock_fd)
{
    int log_mode = O_WRONLY | O_CREAT;
#ifdef TESTING
    if (get_global_DD_APPSEC_TESTING()) {
        log_mode |= O_TRUNC;
    } else {
        log_mode |= O_APPEND;
    }
#else
    log_mode |= O_APPEND;
#endif
    char *helperlog = ZSTR_VAL(get_global_DD_APPSEC_HELPER_LOG_FILE());
    int log_fd = open(helperlog, log_mode, 0600); // NOLINT
    if (log_fd == -1) {
        mlog_err(
            dd_log_warning, "Could not open log file for helper %s", helperlog);
        return dd_error;
    }
    mlog_g(dd_log_debug, "Opened helper log at %s", helperlog);

    int sock_fd = _open_socket_for_helper();
    if (sock_fd == -1) {
        close(log_fd);
        return dd_error;
    }

    char *binary = ZSTR_VAL(get_DD_APPSEC_HELPER_PATH());
    mlog_g(dd_log_debug, "The executable to launch is %s", binary);

    char **argv;
    {
        char *args;
        char *extra_args = ZSTR_VAL(get_DD_APPSEC_HELPER_EXTRA_ARGS());
        spprintf(&args, 0, "%s%s--lock_path - --socket_path fd:%d", extra_args,
            *extra_args ? " " : "", sock_fd);
        argv = _split_params(binary, args);
        efree(args);
    }
    if (!argv) {
        mlog(dd_log_error, "Could not build argument array to launch helper");
        close(log_fd);
        close(sock_fd);
        return dd_error;
    }
    if (dd_log_level() >= dd_log_debug) {
        for (char **arg = argv + 1; *arg; arg++) {
            mlog(dd_log_debug, "    argument: %s", *arg);
        }
    }

    /* fork */
    pid_t pid = fork();
    if (pid != 0) { // parent; the extension
        UNUSED(close(log_fd));
        UNUSED(close(sock_fd));

        if (argv[1]) {
            efree(argv[1]);
        }
        efree(argv);

        if (pid == -1) {
            mlog_err(dd_log_warning, "Failed to fork()");
            return dd_error;
        }

        mlog_g(
            dd_log_info, "Forked. Pid of intermediate process is %d", (int)pid);

        return _wait_for_intermediate_process(pid);
    }

    /* fallback to the intermediary process */
    _continue_in_intermediate_process(log_fd, lock_fd, sock_fd, argv[0], argv);
    return dd_error; // unreachable
}

static int /* fd */ _open_socket_for_helper()
{
    struct sockaddr_un sockaddr = {0};
    sockaddr.sun_family = AF_UNIX;
    if (strlen(_mgr.socket_path) >= sizeof(sockaddr.sun_path) - 1) {
        mlog(dd_log_error,
            "The value of datadog.appsec.socket_path (%s) is "
            "longer than the max size (%zu)",
            _mgr.socket_path, sizeof(sockaddr.sun_path) - 1);
        return -1;
    }
    // NOLINTNEXTLINE(clang-analyzer-security.insecureAPI.strcpy)
    strcpy(sockaddr.sun_path, _mgr.socket_path);

    int res = unlink(_mgr.socket_path);
    if (res == -1 && errno != ENOENT) {
        mlog_err(dd_log_warning, "Failed to unlink %s", _mgr.socket_path);
        return -1;
    }

    int sock_fd = socket(AF_UNIX, SOCK_STREAM, 0);
    if (sock_fd == -1) {
        mlog_err(dd_log_warning, "Call to socket() failed");
        return -1;
    }

    res = bind(sock_fd, (struct sockaddr *)&sockaddr, sizeof(sockaddr));
    if (res == -1) {
        mlog_err(dd_log_warning, "Call to bind() failed");
        UNUSED(close(sock_fd));
        return -1;
    }

#define BACKLOG 20
    res = listen(sock_fd, BACKLOG);
    if (res == -1) {
        mlog_err(dd_log_warning, "Call to listen() failed");
        UNUSED(close(sock_fd));
        return -1;
    }

    mlog(dd_log_info, "Prepared socket for helper. fd %d", sock_fd);

    return sock_fd;
}

/* caller should free returned array and also ret[1] if return is not null */
// NOLINTNEXTLINE(readability-function-cognitive-complexity)
static char **nullable _split_params(
    const char *executable, const char *orig_params_str) // NOLINT
{
    unsigned count = 2; // at least one for executable and one for final null
    char **ret = safe_emalloc(count, sizeof *ret, 0);

    ret[0] = (char *)(uintptr_t)executable;
    ret[1] = NULL;

    const char *p; // read pointer
    for (p = orig_params_str; *p == ' '; p++) {}
    if (*p == '\0') {
        // no arguments
        return ret;
    }

    // we never write more than the original size of the params
    char *params_buffer = emalloc(strlen(orig_params_str) + 1); // NOLINT
    char *wp = params_buffer;                                   // write pointer
    char *param_start; // position of write pointer where we started writing the
                       // current parameter
    enum {
        between,
        double_quoted,
        single_quoted,
        bare_param,
    } state = between;

    bool escaped = false;
    param_start = wp;
    for (; *p != '\0'; p++) {
        switch (state) { // NOLINT
        case between:
            if (*p == ' ') {
                // nothing to do
            } else if (*p == '"') {
                state = double_quoted;
                param_start = wp;
            } else if (*p == '\'') {
                state = single_quoted;
                param_start = wp;
            } else if (*p == '\\') {
                state = bare_param;
                escaped = true;
                param_start = wp;
            } else {
                state = bare_param;
                param_start = wp;
                *wp++ = *p;
            } // next is \0: nothing to do
            break;
        case double_quoted:
        case single_quoted:
            if (escaped) {
                *wp++ = *p;
                escaped = false;
            } else if (*p == (state == double_quoted ? '"' : '\'')) {
                state = bare_param;
            } else if (*p == '\\') {
                escaped = true;
            } else {
                *wp++ = *p;
            } // next is \0: we will trigger failure
            break;
        case bare_param: {
            if (escaped) {
                *wp++ = *p;
                escaped = false;
            } else if (*p == '\\') {
                escaped = true;
            } else if (*p == '"') {
                state = double_quoted;
            } else if (*p == '\'') {
                state = single_quoted;
            } else if (*p == ' ') {
                *wp++ = '\0';
                count++;
                ret = safe_erealloc(ret, count, sizeof *ret, 0);
                ret[count - 2] = param_start;
                ret[count - 1] = NULL;
                state = between;
            } else {
                *wp++ = *p;
            }
            break;
        }
        } // end switch
    }     // end loop

    if (escaped) {
        mlog(dd_log_warning,
            "datadog.appsec.helper_extra_args has an unpaired \\ at the end: "
            "%s",
            orig_params_str);
        efree(ret);
        efree(params_buffer);
        return NULL;
    }
    if (state == single_quoted || state == double_quoted) {
        mlog(dd_log_warning,
            "datadog.appsec.helper_extra_args has unmatched quotes: %s",
            orig_params_str);
        efree(ret);
        efree(params_buffer);
        return NULL;
    }

    if (state != between) {
        *wp = '\0';
        count++;
        ret = safe_erealloc(ret, count, sizeof *ret, 0);
        ret[count - 2] = param_start;
        ret[count - 1] = NULL;
    }

    return ret;
}

static dd_result _wait_for_intermediate_process(pid_t pid)
{
    int stat_loc;
    if (waitpid(pid, &stat_loc, 0) == -1) {
        mlog_err(dd_log_info, "Call to waitpid() failed");
        return dd_error;
    }
    if (WIFEXITED(stat_loc) && WEXITSTATUS(stat_loc) == 0) {
        mlog_g(dd_log_debug, "Intermediate process terminated normally");
    } else {
        if (WIFEXITED(stat_loc)) {
            mlog(dd_log_warning,
                "Intermediate process %d exited with exit code %d", (int)pid,
                WEXITSTATUS(stat_loc));
        } else if (WIFSIGNALED(stat_loc)) {
            mlog(dd_log_warning,
                "Intermediate process %d was signaled. Signal %d (dump: "
                "%s)",
                (int)pid, WTERMSIG(stat_loc),
                WCOREDUMP(stat_loc) ? "yes" : "no");
        } else if (WIFSTOPPED(stat_loc)) {
            mlog(dd_log_warning,
                "Intermediate process %d was stopped. Signal %d", (int)pid,
                WTERMSIG(stat_loc));
        } else {
            mlog_g(dd_log_warning,
                "Intermediate process %d did not end normally; "
                "value of stat_loc is %d",
                (int)pid, stat_loc);
        }
        return dd_error;
    }

    return dd_success;
}

#define PREEXEC_LOG(msgf) _preexec_log(log_fd, msgf, sizeof(msgf) - 1)
static void _preexec_log(int log_fd, const char *msg, size_t msg_len)
{
    struct timespec ts;
    clock_gettime(CLOCK_REALTIME, &ts);

    struct tm tinfo;
    localtime_r(&ts.tv_sec, &tinfo);
    char buffer[sizeof("[2010-01-01 00:00:00.000")];
    size_t ret = strftime(buffer, sizeof(buffer), "[%F %T", &tinfo);
    if (!ret) {
        buffer[0] = '\0';
    }

    size_t buffer_len = strlen(buffer);
#define TEN_E6 1000000
    snprintf(&buffer[buffer_len], sizeof(buffer) - buffer_len, ".%03ld",
        (long)ts.tv_nsec / TEN_E6);
    UNUSED(write(log_fd, buffer, strlen(buffer)));
    UNUSED(write(log_fd, ZEND_STRL("] pre-exec: ")));
    UNUSED(write(log_fd, msg, msg_len));
    UNUSED(write(log_fd, "\n", 1));
}

#define EXIT_SIGNALS_STATE 9
#define EXIT_GETFD 2
#define EXIT_FD_OPEN 3
#define EXIT_UNEXPECTED_FD 4
#define EXIT_SIGACTION 5
#define EXIT_SIGHUP_IGNORE 6
#define EXIT_SETSID 7
#define EXIT_SECOND_FORK 8
#define EXIT_CODE_MASK 0x7F

static ATTR_NO_RETURN void _continue_in_intermediate_process(
    int log_fd, int lock_fd, int sock_fd, const char *executable, char **argv)
{
    /* we can't log with mlog anymore! */

    /* close all file descriptors except lock_fd and sock_fd*/
    _close_file_descriptors(log_fd, lock_fd, sock_fd);

    /* set default handlers and empty signal mask */
    if (!_reset_signals_state(log_fd)) {
        exit(EXIT_SIGNALS_STATE); // NOLINT(concurrency-mt-unsafe)
    }

    /* check that the remaining file descriptors are valid */
    int fildes[] = {log_fd, lock_fd, sock_fd};
    for (size_t i = 0; i < ARRAY_SIZE(fildes); i++) {
        if (fcntl(fildes[i], F_GETFD) == -1) {
            PREEXEC_LOG("call to fcntl F_GETFD failed");
            exit(EXIT_GETFD); // NOLINT(concurrency-mt-unsafe)
        }
    }

    /* open stdin, stdout /dev/null and stderr as dup of log_fd (typically
     * /dev/null too, generally the value of datadog.appsec.helper_log_file */
    int fd0 = open("/dev/null", O_RDWR); // NOLINT
    int fd1 = dup(0);                    // NOLINT
    int fd2 = dup2(log_fd, 2);
    close(log_fd);
    log_fd = fd2; // for PREEXEC_LOG macro

    if (fd0 == -1 || fd1 == -1 || fd2 == -1) {
        PREEXEC_LOG("failed opening one of the standard file descriptors");
        exit(EXIT_FD_OPEN); // NOLINT(concurrency-mt-unsafe)
    }

    if (fd0 != 0 || fd1 != 1 || fd2 != 2) {
        PREEXEC_LOG("one of the opened files did not have the expect file "
                    "descriptor number");
        exit(EXIT_UNEXPECTED_FD); // NOLINT(concurrency-mt-unsafe)
    }

    if (chdir("/") == -1) {
        PREEXEC_LOG("could not chdir /");
        // not fatal
    }

    umask(0); // can't fail

    struct sigaction sa = {.sa_handler = SIG_IGN, .sa_flags = 0};
    if (sigemptyset(&sa.sa_mask) == -1) {
        exit(EXIT_SIGACTION); // NOLINT(concurrency-mt-unsafe)
    }

    if (sigaction(SIGHUP, &sa, NULL) == -1) {
        PREEXEC_LOG("could not make SIGHUP ignored");
        exit(EXIT_SIGHUP_IGNORE); // NOLINT(concurrency-mt-unsafe)
    }

    /* fork again */
    PREEXEC_LOG("Going for second fork");
    pid_t pid = fork();
    if (pid == -1) {
        PREEXEC_LOG("Second fork failed");
        exit(EXIT_SECOND_FORK); // NOLINT(concurrency-mt-unsafe)
    }
    if (pid != 0) { // parent
        PREEXEC_LOG("Intermediate process exiting");
        // skip atexit() hooks
        _Exit(0); // NOLINT(concurrency-mt-unsafe)
    }

    if (setsid() == -1) {
        PREEXEC_LOG("Call to setsid() failed");
        exit(EXIT_SETSID); // NOLINT(concurrency-mt-unsafe)
    }

    PREEXEC_LOG("About to call execv");
    if (execv(executable, argv) == -1) {
        int exit_code = (errno & EXIT_CODE_MASK) ? (errno & EXIT_CODE_MASK) : 1;
        PREEXEC_LOG("execv call failed");
        exit(exit_code); // NOLINT(concurrency-mt-unsafe)
    }

    __builtin_unreachable();
}

#define DEFAULT_BASE 10
#define MAX_RLIMIT 1024

static void _close_file_descriptors(int log_fd, int lock_fd, int sock_fd)
{
    DIR *self = opendir("/proc/self/fd/");
    if (self != NULL) {
        struct dirent *entry = NULL;
        // NOLINTNEXTLINE(concurrency-mt-unsafe)
        while ((entry = readdir(self)) != NULL) {
            if (*entry->d_name == '.') {
                continue;
            }

            char *endptr = NULL;
            int fd = (int)strtol(entry->d_name, &endptr, DEFAULT_BASE);
            if (endptr != entry->d_name && *endptr == '\0' && fd != log_fd &&
                fd != lock_fd && fd != sock_fd) {
                close(fd);
            }
        }

        closedir(self);
    } else {
        int fd_max;
        struct rlimit rl;

        // Determine max number of file descriptors, default and limit to 1024
        if (getrlimit(RLIMIT_NOFILE, &rl) == -1 || rl.rlim_max > MAX_RLIMIT) {
            fd_max = MAX_RLIMIT;
        } else {
            fd_max = (int)rl.rlim_max;
        }

        for (int i = 0; i < fd_max; i++) {
            if (i != log_fd && i != lock_fd && i != sock_fd) {
                close(i);
            }
        }
    }
}

static bool _reset_signals_state(int log_fd)
{
#ifndef SIGRTMIN
#    define SIGRTMIN 32
#endif
    // ignored signals are kept ignored across exec()
    // NOLINTNEXTLINE(cert-err33-c)
    for (int i = 1; i < SIGRTMIN; i++) { signal(i, SIG_DFL); }

    // the signal mask is also kept across exec()
    sigset_t empty_mask;
    sigemptyset(&empty_mask);

    // NOLINTNEXTLINE(concurrency-mt-unsafe)
    if (sigprocmask(SIG_SETMASK, &empty_mask, NULL) == -1) {
        PREEXEC_LOG("Call to sigprocmask failed");
        return false;
    }

    return true;
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

    // also, if we launched, do not try again and prevent others from trying too
    if (_mgr.launched_this_req) {
        int lock_fd = _acquire_lock();
        if (lock_fd == -1) {
            mlog(dd_log_info, "Could not acquire exclusive lock to prevent "
                              "further helper launching");
            return;
        }

        _prevent_launch_attempts(lock_fd);
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
static PHP_FUNCTION(datadog_appsec_testing_set_helper_extra_args)
{
    zend_string *zstr;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &zstr) == FAILURE) {
        RETURN_FALSE;
    }

    zend_alter_ini_entry(
        zai_config_memoized_entries[DDAPPSEC_CONFIG_DD_APPSEC_HELPER_EXTRA_ARGS]
            .ini_entries[0]
            ->name,
        zstr, ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME);
}
static PHP_FUNCTION(datadog_appsec_testing_get_helper_argv)
{
    array_init(return_value);
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    char **argv = _split_params(ZSTR_VAL(get_DD_APPSEC_HELPER_PATH()),
        ZSTR_VAL(get_DD_APPSEC_HELPER_EXTRA_ARGS()));
    if (!argv) {
        return;
    }

    for (char **s = argv; *s; s++) { add_next_index_string(return_value, *s); }

    if (argv[1]) {
        efree(argv[1]);
    }
    efree(argv);
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
    ZEND_RAW_FENTRY(DD_TESTING_NS "set_helper_extra_args", PHP_FN(datadog_appsec_testing_set_helper_extra_args), set_string_arginfo, 0)
    ZEND_RAW_FENTRY(DD_TESTING_NS "get_helper_argv", PHP_FN(datadog_appsec_testing_get_helper_argv), void_ret_array_arginfo, 0)
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
