#include "logging.h"
#include "sidecar.h"

#include <fcntl.h>
#include <stdio.h>
#include <string.h>
#include <time.h>

#include "configuration.h"
#include <main/SAPI.h>


static void dd_log_set_level(bool debug) {
    bool once = runtime_config_first_init ? get_DD_TRACE_ONCE_LOGS() : get_global_DD_TRACE_ONCE_LOGS();
    if (debug) {
        if (strcmp("cli", sapi_module.name) != 0 && (runtime_config_first_init ? get_DD_TRACE_STARTUP_LOGS() : get_global_DD_TRACE_STARTUP_LOGS())) {
            ddog_set_log_level(DDOG_CHARSLICE_C("debug"), once);
        } else {
            ddog_set_log_level(DDOG_CHARSLICE_C("debug,startup=error"), once);
        }
    } else if (runtime_config_first_init) {
        ddog_set_log_level(dd_zend_string_to_CharSlice(get_DD_TRACE_LOG_LEVEL()), once);
    } else if (zend_string_equals_literal_ci(Z_STR(zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_LOG_LEVEL].decoded_value), "error")) {
        ddog_set_error_log_level(once); // optimized handling without parsing
    } else {
        ddog_set_log_level(dd_zend_string_to_CharSlice(get_global_DD_TRACE_LOG_LEVEL()), once);
    }
}

// We need to ensure that logging is initialized (i.e. set) at least once per thread.
void ddtrace_log_ginit(void) {
    dd_log_set_level(get_global_DD_TRACE_DEBUG());
}

_Atomic(int) ddtrace_error_log_fd = -1;
_Atomic(uintmax_t) dd_error_log_fd_rotated = 0;

void ddtrace_log_minit(void) {
    if (ZSTR_LEN(get_global_DD_TRACE_LOG_FILE())) {
        int fd = VCWD_OPEN_MODE(ZSTR_VAL(get_global_DD_TRACE_LOG_FILE()), O_RDWR | O_APPEND, 0666);
        if (fd < 0) {
            // Retry with CREAT to only apply fchmod() on CREAT
            fd = VCWD_OPEN_MODE(ZSTR_VAL(get_global_DD_TRACE_LOG_FILE()), O_CREAT | O_RDWR | O_APPEND, 0666);
            if (fd < 0) {
                return;
            }
#ifndef _WIN32
            fchmod(fd, 0666); // ignore umask
#endif
        }
        atomic_store(&ddtrace_error_log_fd, fd);

        time_t now;
        time(&now);
        atomic_store(&dd_error_log_fd_rotated, (uintmax_t) now);
    }

    // no need to call dd_log_set_level here, ddtrace_config_minit() inits the debug config
}

void ddtrace_log_rinit(char *error_log) {
    if (atomic_load(&ddtrace_error_log_fd) != -1) {
        return;
    }

    if (!error_log || strcasecmp(error_log, "syslog") == 0 || strlen(error_log) == 0) {
        return;
    }

    int desired = VCWD_OPEN_MODE(error_log, O_RDWR | O_APPEND, 0666);
    if (desired < 0) {
        // Retry with CREAT to only apply fchmod() on CREAT
        desired = VCWD_OPEN_MODE(error_log, O_CREAT | O_RDWR | O_APPEND, 0666);

#ifndef _WIN32
        if (desired >= 0) {
            fchmod(desired, 0666); // ignore umask
        }
#endif
    }

    time_t now;
    time(&now);
    atomic_store(&dd_error_log_fd_rotated, (uintmax_t) now);
    int expected = -1;
    if (!atomic_compare_exchange_strong(&ddtrace_error_log_fd, &expected, desired)) {
        // if it didn't exchange, then we need to free it
        close(desired);
    }
}

int ddtrace_get_fd_path(int fd, char *buf) {
#ifdef _WIN32
    intptr_t handle = _get_osfhandle(fd);
    if (handle == INVALID_HANDLE_VALUE) {
        return -1;
    }
    return GetFinalPathNameByHandleA((HANDLE) handle, buf, MAXPATHLEN, VOLUME_NAME_DOS) ? 1 : -1;
#elif defined(F_GETPATH)
    return fcntl(fd, F_GETPATH, buf);
#else
    char pathbuf[MAXPATHLEN];
    snprintf(pathbuf, MAXPATHLEN, "/proc/self/fd/%d", fd);
    int len = readlink(pathbuf, buf, PATH_MAX);
    if (len >= 0) {
        buf[len] = 0;
    }
    return len;
#endif
}

void ddtrace_log_mshutdown(void) {
    int error_log_fd = atomic_load(&ddtrace_error_log_fd);
    atomic_store(&ddtrace_error_log_fd, -1);
    if (error_log_fd != -1) {
        close(error_log_fd);
    }
}

int ddtrace_log_with_time(int fd, const char *msg, int msg_len) {
    // todo: we only need 20-ish for the main part, but how much for the timezone?
    // Wish PHP printed -hhmm or +hhmm instead of the name
    char *msgbuf = malloc(msg_len + 70);

    time_t now;
    time(&now);
    struct tm *now_local = localtime(&now);
    char *p = msgbuf;
    *(p++) = '[';
    int time_len = (int)strftime(p, 64, "%d-%b-%Y %H:%M:%S %Z", now_local);
    if (time_len > 0) {
        p += time_len;
    }
    *(p++) = ']';
    *(p++) = ' ';
    memcpy(p, msg, msg_len);
    p += msg_len;
    *(p++) = '\n';

    uintmax_t last_check = atomic_exchange(&dd_error_log_fd_rotated, (uintmax_t) now);
    if (last_check < (uintmax_t)now - 60) { // 1x/min
        char pathbuf[MAXPATHLEN];
        if (ddtrace_get_fd_path(fd, pathbuf) >= 0) {
            int new_fd = VCWD_OPEN_MODE(pathbuf, O_RDWR | O_APPEND, 0666);
            if (new_fd < 0) {
                // Retry with CREAT to only apply fchmod() on CREAT
                new_fd = VCWD_OPEN_MODE(pathbuf, O_CREAT | O_RDWR | O_APPEND, 0666);
#ifndef _WIN32
                fchmod(new_fd, 0666); // ignore umask
#endif
            }
            dup2(new_fd, fd); // atomic replace
            close(new_fd);
        }
    }

    int ret = write(fd, msgbuf, p - msgbuf);

    free(msgbuf);
    return ret;
}

#undef ddtrace_bgs_logf
int ddtrace_bgs_logf(const char *fmt, ...) {
    int ret = 0;
    int error_log_fd = atomic_load(&ddtrace_error_log_fd);
    if (error_log_fd != -1) {
        va_list args, args_copy;
        va_start(args, fmt);

        va_copy(args_copy, args);
        int needed_len = vsnprintf(NULL, 0, fmt, args_copy);
        va_end(args_copy);

        char *msgbuf = malloc(needed_len);
        vsnprintf(msgbuf, needed_len, fmt, args);
        va_end(args);

        ret = ddtrace_log_with_time(error_log_fd, msgbuf, needed_len);

        free(msgbuf);
    }

    return ret;
}

static void ddtrace_log_callback(ddog_CharSlice msg) {
    char *message = (char*)msg.ptr;
    int error_log_fd = atomic_load(&ddtrace_error_log_fd);
    if (error_log_fd != -1) {
        ddtrace_log_with_time(error_log_fd, message, (int)msg.len);
    } else {
        if (msg.ptr[msg.len]) {
            message = zend_strndup(msg.ptr, msg.len);
            php_log_err(message);
            free(message);
        } else {
            php_log_err(message);
        }
    }
}


void ddtrace_log_init(void) {
    ddog_log_callback = ddtrace_log_callback;
}

bool ddtrace_alter_dd_trace_debug(zval *old_value, zval *new_value, zend_string *new_str) {
    UNUSED(old_value, new_str);

    dd_log_set_level(Z_TYPE_P(new_value) == IS_TRUE);

    return true;
}

bool ddtrace_alter_dd_trace_log_level(zval *old_value, zval *new_value, zend_string *new_str) {
    UNUSED(old_value, new_str);
    if (runtime_config_first_init ? get_DD_TRACE_DEBUG() : get_global_DD_TRACE_DEBUG()) {
        return true;
    }

    ddog_set_log_level(dd_zend_string_to_CharSlice(Z_STR_P(new_value)), runtime_config_first_init ? get_DD_TRACE_ONCE_LOGS() : get_global_DD_TRACE_ONCE_LOGS());

    return true;
}
