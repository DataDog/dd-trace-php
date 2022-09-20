// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "logging.h"
#include "attributes.h"
#include "ddappsec.h"
#include "dddefs.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "php_objects.h"
#include "string_helpers.h"
#include <TSRM.h>
#include <fcntl.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <syslog.h>
#include <time.h>
#ifdef __linux__
#    include <sys/syscall.h>
#endif

typedef enum {
    log_use_nothing,
    log_use_file,
    log_use_syslog,
    log_use_php_err_rep,
} log_strategy;

THREAD_LOCAL_ON_ZTS dd_log_level_t dd_log_level;

#ifdef ZTS
__thread char _dd_strerror_buf[1024]; // NOLINT
#endif
const int _dd_size_source_prefix = sizeof(__FILE__) - sizeof("logging.c");

static atomic_bool _initialized;
// mutex used for two purpose:
//  * initialization (we do it lazily on first log message, not MINIT)
//  * avoid two threads writing lines to the log file the same time
#ifdef ZTS
static MUTEX_T _mutex;
#endif
static int _mlog_fd = -1;
static log_strategy _log_strategy;
static const char *_log_file;

#define PHP_MSG_PREFIX "[ddappsec] "
#define DEFAULT_LOG_FILE_NAME "ddog-appsec-php.log"
#define FORMAT_TIME_BUF_SIZE(precision)                                        \
    ((sizeof "2018-01-08T12:50:47.Z") + (precision))

#define SYSLOG_IDENT "ddog-appsec-php-ext"

typedef int (*strerror_r_t)(int errnum, char *buf, size_t buflen);
static strerror_r_t _libc_strerror_r;
static void _find_strerror_r(void);
static void _ensure_init(void);
static dd_result _do_dd_log_init(void);
static void ATTR_FORMAT(2, 3)
    _mlog_php_varargs(dd_log_level_t level, const char *format, ...);
static int _log_level_to_syslog_pri(dd_log_level_t log_level);
static void _format_time(
    char *buf, size_t buf_size, struct timespec *time, int precision);
static ZEND_INI_MH(_on_update_log_level);
static ZEND_INI_MH(_on_update_log_file);

#ifdef TESTING
static void _register_testing_objects(void);
#endif

// clang-format off
static const dd_ini_setting ini_settings[] = {
    DD_INI_ENV("log_level", "warn", PHP_INI_ALL, _on_update_log_level),
    DD_INI_ENV("log_file", "php_error_reporting", PHP_INI_SYSTEM, _on_update_log_file),
    {0}
};
// clang-format on

static void _dd_log_errf(const char *format, ...)
{
    va_list args;
    char *buffer;

    va_start(args, format);
    vspprintf(&buffer, 0, format, args);
    php_log_err(buffer);

    efree(buffer);
    va_end(args);
}

void dd_log_startup()
{
#ifdef ZTS
    _mutex = tsrm_mutex_alloc();
#endif

    _find_strerror_r();

    dd_phpobj_reg_ini_envs(ini_settings);

#ifdef TESTING
    _register_testing_objects();
#endif
}

int _strerror_r_fallback(int errnum, char *buf, size_t buflen)
{
    strncpy(buf, strerror(errnum), buflen - 1); // NOLINT
    buf[buflen - 1] = '\0';
    return 0;
}
static void _find_strerror_r()
{
    _libc_strerror_r = dlsym(NULL, "__xpg_strerror_r");
    if (!_libc_strerror_r) {
        _libc_strerror_r = dlsym(NULL, "strerror_r");
        if (!_libc_strerror_r) {
            _libc_strerror_r = _strerror_r_fallback;
        }
    }
}

static void _ensure_init()
{
#ifdef ZTS
    /* assert: mlog is not called before dd_log_startup */
    assert(_mutex != NULL);
#endif

    if (!atomic_load_explicit(&_initialized, memory_order_acquire)) {
        TSRM_MUTEX_LOCK(_mutex);
        if (!atomic_load(&_initialized)) {
            dd_result res = _do_dd_log_init();
            if (res == dd_error) {
                _mlog_php_varargs(
                    dd_log_warning, "Could not initialize logging");
                _log_strategy = log_use_nothing;
            }
            if (res != dd_try_later) {
                atomic_store_explicit(
                    &_initialized, true, memory_order_release);
            } else {
                // While it cant log to file, lets hide the messages
                _log_strategy = log_use_nothing;
            }
        }
        TSRM_MUTEX_UNLOCK(_mutex);
    }
}

static dd_result _do_dd_log_init() // guarded by mutex
{
    const char *path = ""; // compiler can't tell it's always used initialized

    if (_log_file && *_log_file) {
        size_t log_file_len = strlen(_log_file);
        if (STR_CONS_EQ(_log_file, log_file_len, "syslog")) {
            openlog(SYSLOG_IDENT, LOG_PID, LOG_USER);
            _log_strategy = log_use_syslog;
        } else if (STR_CONS_EQ(_log_file, log_file_len, "stdout")) {
            _log_strategy = log_use_file;
            _mlog_fd = fileno(stdout);
        } else if (STR_CONS_EQ(_log_file, log_file_len, "stderr")) {
            _log_strategy = log_use_file;
            _mlog_fd = fileno(stderr);
        } else if (STR_CONS_EQ(
                       _log_file, log_file_len, "php_error_reporting")) {
            _log_strategy = log_use_php_err_rep;
        } else {
            _log_strategy = log_use_file;
            path = _log_file;
        }
    } else {
        _log_strategy = log_use_php_err_rep;
    }

    if (_log_strategy != log_use_file || _mlog_fd != -1) {
        return dd_success;
    }

    int at_request = PG(modules_activated) || PG(during_request_startup);

    // ignores open_basedir
    int mode = O_WRONLY | O_APPEND | O_NOFOLLOW;
    if (DDAPPSEC_NOCACHE_G(testing)) {
        mode |= O_TRUNC;
    }
    // Minit/Mshutdown are run by root on some sapis. Creating the log file as
    // root will avoid other users to log messages
    if (at_request) {
        mode |= O_CREAT;
    }
    _mlog_fd = open(path, mode, 0644); // NOLINT
    if (_mlog_fd == -1) {
        if (!at_request && errno == ENOENT) {
            return dd_try_later;
        }
        _mlog_php_varargs(dd_log_warning,
            "Error opening log file '%s' (errno %d: %s)", path, errno,
            _strerror(errno));
        return dd_error;
    }

    return dd_success;
}

static int _dd_log_level_from_str(const char *nullable log_level)
{
    if (log_level == NULL) {
        goto err;
    }

    size_t len = strlen((const char *)log_level);
    if (dd_string_equals_lc(log_level, len, ZEND_STRL("off"))) {
        return dd_log_off;
    }
    if (dd_string_equals_lc(log_level, len, ZEND_STRL("fatal"))) {
        return dd_log_fatal;
    }
    if (dd_string_equals_lc(log_level, len, ZEND_STRL("error"))) {
        return dd_log_error;
    }
    if (dd_string_equals_lc(log_level, len, ZEND_STRL("warning")) ||
        dd_string_equals_lc(log_level, len, ZEND_STRL("warn"))) {
        return dd_log_warning;
    }
    if (dd_string_equals_lc(log_level, len, ZEND_STRL("info"))) {
        return dd_log_info;
    }
    if (dd_string_equals_lc(log_level, len, ZEND_STRL("debug"))) {
        return dd_log_debug;
    }
    if (dd_string_equals_lc(log_level, len, ZEND_STRL("trace"))) {
        return dd_log_trace;
    }

err:
    /* Fallback on a reasonable log level */
    return -1;
}

static const char *nonnull _dd_log_level_to_str(dd_log_level_t log_level)
{
    switch (log_level) {
    case dd_log_off:
        return "off";
    case dd_log_fatal:
        return "fatal";
    case dd_log_error:
        return "error";
    case dd_log_warning:
        return "warning";
    case dd_log_info:
        return "info";
    case dd_log_debug:
        return "debug";
    case dd_log_trace:
        return "trace";
    default:
        return "unknown";
    }
}

// syslog
static void _mlog_syslog(dd_log_level_t level, const char *format, va_list args,
    const char *file, const char *function, int line)
{
    char *message_data;
    int prio = _log_level_to_syslog_pri(level);

    vspprintf(&message_data, 0, format, args);

#if !defined(ZTS)
    syslog(prio, "%s %d:%s %s",
#else
    syslog(prio, "[%ld] %s:%d:%s %s", (long)tsrm_thread_id(),
#endif
        file, line, function, message_data);

    efree(message_data);
}
static int _log_level_to_syslog_pri(dd_log_level_t log_level)
{
    switch (log_level) {
    case dd_log_fatal:
        return LOG_CRIT;
    case dd_log_error:
        return LOG_ERR;
    case dd_log_warning:
        return LOG_WARNING;
    case dd_log_info:
        return LOG_NOTICE;
    case dd_log_debug:
    case dd_log_trace:
        return LOG_DEBUG;
    case dd_log_off:
    default:
        return LOG_WARNING;
    }
}

// php error reporting
static int _log_level_to_php_err_reporting_pri(dd_log_level_t log_level)
{
    switch (log_level) {
    case dd_log_fatal:
    case dd_log_error:
    case dd_log_warning:
        return E_WARNING;
    case dd_log_info:
    case dd_log_debug:
    case dd_log_trace:
    case dd_log_off:
    default:
        return E_NOTICE;
    }
}

static void _mlog_php(dd_log_level_t level, const char *format, va_list args)
{
    size_t format_len = strlen(format);
    char *new_fmt =
        safe_emalloc(format_len, 1, LSTRLEN(PHP_MSG_PREFIX) + 1 /* \0 */);
    // NOLINTNEXTLINE(clang-analyzer-security.insecureAPI.DeprecatedOrUnsafeBufferHandling)
    memcpy(new_fmt, PHP_MSG_PREFIX, LSTRLEN(PHP_MSG_PREFIX));
    // NOLINTNEXTLINE(clang-analyzer-security.insecureAPI.DeprecatedOrUnsafeBufferHandling)
    memcpy(new_fmt + LSTRLEN(PHP_MSG_PREFIX), format, format_len + 1);

    // temporarily disable user handling of these messages, as that
    // can cause corruption
    const int orig_ueher = EG(user_error_handler_error_reporting);
    EG(user_error_handler_error_reporting) &= ~(E_WARNING | E_NOTICE);

    const zend_error_handling_t orig_err_handling = EG(error_handling);
    EG(error_handling) = EH_NORMAL;

    const int php_log_level = _log_level_to_php_err_reporting_pri(level);
    php_verror(NULL, "", php_log_level, new_fmt, args);

    EG(error_handling) = orig_err_handling;
    EG(user_error_handler_error_reporting) = orig_ueher;
    efree(new_fmt);
}

static void ATTR_FORMAT(2, 3)
    _mlog_php_varargs(dd_log_level_t level, const char *format, ...)
{
    va_list va;
    va_start(va, format);
    _mlog_php(level, format, va);
    va_end(va);
}

// file reporting
static void _mlog_file(dd_log_level_t level, const char *format, va_list args,
    const char *file, const char *function, int line)
{
    if (_mlog_fd == -1) {
        return;
    }

    char *message_data;
    char *data;
    size_t data_len;

    vspprintf(&message_data, 0, format, args);

    char time_str[FORMAT_TIME_BUF_SIZE(3)] = "";
    struct timespec ts = {0};
    if (clock_gettime(CLOCK_REALTIME, &ts) != -1) {
        _format_time(time_str, sizeof time_str, &ts, 3);
    }

#if !defined(ZTS)
    data_len =
        spprintf(&data, 0, "[%s][%d][%s] %s at %s:%d:%s\n", time_str, getpid(),
#else
    data_len = spprintf(&data, 0, "[%s][%d:%ld][%s] %s at %s:%d:%s\n", time_str,
        getpid(),
#    ifdef __linux__
        syscall(__NR_gettid),
#    else
        (long)tsrm_thread_id(),
#    endif
#endif
            _dd_log_level_to_str(level), message_data, file, line, function);

    efree(message_data);
    TSRM_MUTEX_LOCK(_mutex);
    ssize_t written = write(_mlog_fd, data, data_len);
    TSRM_MUTEX_UNLOCK(_mutex);
    efree(data);

    if (written == -1) {
        _mlog_php_varargs(dd_log_warning,
            "Failed writing to log file (errno %d: %s)", errno,
            _strerror(errno));
    } else if ((size_t)written < data_len) {
        _mlog_php_varargs(dd_log_warning,
            "Failed writing full data to log file (%zd < %zu)", written,
            data_len);
    }
}
static void _format_time(
    char *buf, size_t buf_size, struct timespec *time, int precision)
{
    struct tm tm = {0};
    gmtime_r(&time->tv_sec, &tm);
    size_t len = strftime(buf, buf_size, "%FT%T", &tm);
    size_t left_size = buf_size - len;
    if (UNEXPECTED(left_size > buf_size)) {
        *buf = '\0';
        return;
    }
    snprintf(&buf[len], left_size, ".%0*ldZ", precision,
        time->tv_nsec / (long)pow(10.0, 9.0 - precision)); // NOLINT
    buf[buf_size - 1] = '\0'; /* shouldn't be needed */
}

void _mlog_relay(dd_log_level_t level, const char *nonnull format,
    const char *nonnull file, const char *nonnull function, int line, ...)
{
    va_list args;

    if (dd_log_level < level) {
        return;
    }

    _ensure_init();

    va_start(args, line);
    /* Always show errors on PHP error reporting, regardless of settings */
    if (_log_strategy != log_use_php_err_rep && level <= dd_log_error) {
        va_list args2;
        va_copy(args2, args);
        _mlog_php(level, format, args2);
        va_end(args2);
    }

    switch (_log_strategy) {
    case log_use_syslog:
        _mlog_syslog(level, format, args, file, function, line);
        break;
    case log_use_php_err_rep: {
        _mlog_php(level, format, args);
        break;
    }
    case log_use_file:
        _mlog_file(level, format, args, file, function, line);
        break;
    case log_use_nothing:
    default:
        // nothing to do
        break;
    }
    va_end(args);
}

void dd_log_shutdown()
{
    mlog(dd_log_debug, "Shutting down the file logging");

#ifdef ZTS
    tsrm_mutex_free(_mutex);
    _mutex = NULL;
#endif
    if (_log_strategy == log_use_file && _mlog_fd != -1 &&
        _mlog_fd > fileno(stderr)) {
        int ret = close(_mlog_fd);
        if (ret == -1) {
            _mlog_php_varargs(dd_log_warning,
                "Error closing the log file (errno %d: %s)", errno,
                _strerror(errno));
        }
    } else if (_log_strategy == log_use_syslog) {
        closelog();
    }

    _mlog_fd = -1;
    _log_strategy = log_use_nothing;
    atomic_store(&_initialized, false);
}

const char *nonnull _strerror_r(int err, char *nonnull buf, size_t buflen)
{
    int res = _libc_strerror_r(err, buf, buflen);
    if (res != 0) {
        buf[0] = '\0';
    }
    return buf;
}

static ZEND_INI_MH(_on_update_log_level)
{
    ZEND_INI_MH_UNUSED();
    if (!new_value || !ZSTR_VAL(new_value)[0]) {
        return FAILURE;
    }

    char *str_value = ZSTR_VAL(new_value);
    int level = _dd_log_level_from_str(str_value);
    if (level == -1) {
        return FAILURE;
    }

    dd_log_level = level;
    return SUCCESS;
}
static ZEND_INI_MH(_on_update_log_file)
{
    ZEND_INI_MH_UNUSED();
    if (!new_value || !ZSTR_VAL(new_value)[0]) {
        return FAILURE;
    }
    if (_log_strategy != log_use_nothing && ZSTR_VAL(new_value) != _log_file) {
        _dd_log_errf("Cannot change datadog.appsec.log_file anymore");
        return FAILURE; // change not possible already
    }

    _log_file = ZSTR_VAL(new_value);
    return SUCCESS;
}

#ifdef TESTING
static PHP_FUNCTION(datadog_appsec_testing_mlog)
{
    zend_long level;
    zend_string *str;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "lS", &level, &str) == FAILURE) {
        RETURN_FALSE;
    }

    if (level < dd_log_off || level > dd_log_trace) {
        _dd_log_errf("Level %lld is out of range", (long long)level);
        RETURN_FALSE;
    }
    if (str->len > INT_MAX) {
        _dd_log_errf("String is too long");
        RETURN_FALSE;
    }

    mlog((dd_log_level_t)level, "%.*s", (int)str->len, str->val);
    RETURN_TRUE;
}
// for closing stderr/stdout. fclose(STDERR) doesn't seem to reliably work
// across PHP versions
static PHP_FUNCTION(datadog_appsec_testing_fdclose)
{
    zend_long fd;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &fd) == FAILURE) {
        RETURN_FALSE;
    }

    int res = close((int)fd);
    if (res == -1) {
        RETURN_FALSE;
    }
    RETURN_TRUE;
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(mlog, 0, 2, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, level, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(fdclose, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, fd, IS_LONG, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "mlog", PHP_FN(datadog_appsec_testing_mlog), mlog, 0)
    ZEND_RAW_FENTRY(DD_TESTING_NS "fdclose", PHP_FN(datadog_appsec_testing_fdclose), fdclose, 0)
    PHP_FE_END
};
// clang-format on
static void _register_testing_objects()
{
    if (!DDAPPSEC_G(testing)) {
        return;
    }

    dd_phpobj_reg_funcs(functions);

#    define _REG_LOG_LEVEL(php_name, value)                                    \
        do {                                                                   \
            char v[] = "datadog\\appsec\\testing\\log_level\\" php_name;       \
            dd_phpobj_reg_long_const(                                          \
                v, sizeof(v) - 1, value, CONST_CS | CONST_PERSISTENT);         \
        } while (0)

    _REG_LOG_LEVEL("OFF", dd_log_off);
    _REG_LOG_LEVEL("FATAL", dd_log_fatal);
    _REG_LOG_LEVEL("ERROR", dd_log_error);
    _REG_LOG_LEVEL("WARNING", dd_log_warning);
    _REG_LOG_LEVEL("INFO", dd_log_info);
    _REG_LOG_LEVEL("DEBUG", dd_log_debug);
    _REG_LOG_LEVEL("TRACE", dd_log_trace);
}
#endif
