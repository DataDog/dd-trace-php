// LLVM coverage profiling initialization for helper-rust
//
// This module sets up the LLVM profiling environment when the helper is
// loaded by sidecar. It reinitializes the LLVM profiling runtime to use
// the correct output path.
//
// Coverage data is flushed when the process receives SIGUSR1, as well as
// at normal process exit via atexit handler.

#ifdef COVERAGE_BUILD

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <signal.h>
#include <fcntl.h>
#include <stdarg.h>
#include <time.h>
#include <errno.h>

// LLVM profiling runtime API (from compiler-rt/lib/profile/)
extern void __llvm_profile_initialize_file(void);
extern int __llvm_profile_write_file(void);
extern const char *__llvm_profile_get_filename(void);

static int log_fd = -1;

static void coverage_log(const char *fmt, ...) {
    if (log_fd < 0) {
        log_fd = open("/helper-rust/coverage/coverage_init.log",
                      O_WRONLY | O_CREAT | O_APPEND, 0644);
        if (log_fd < 0) {
            log_fd = STDERR_FILENO;
        }
    }

    char buf[1024];
    int offset = 0;

    time_t now = time(NULL);
    struct tm *tm_info = localtime(&now);
    offset += strftime(buf, sizeof(buf), "[%Y-%m-%d %H:%M:%S] ", tm_info);
    offset += snprintf(buf + offset, sizeof(buf) - offset, "[pid=%d] ", getpid());

    va_list args;
    va_start(args, fmt);
    offset += vsnprintf(buf + offset, sizeof(buf) - offset, fmt, args);
    va_end(args);

    if (offset < (int)sizeof(buf) - 1) {
        buf[offset++] = '\n';
    }

    write(log_fd, buf, offset);
}

static void coverage_flush(void) {
    coverage_log("coverage_flush() called, flushing coverage data");

    int ret = __llvm_profile_write_file();
    coverage_log("__llvm_profile_write_file() returned %d (errno=%d: %s)",
                 ret, errno, strerror(errno));

    const char *filename = __llvm_profile_get_filename();
    coverage_log("Final profile filename: %s", filename ? filename : "(null)");
}

static void coverage_atexit(void) {
    coverage_log("coverage_atexit() called");
    coverage_flush();
    if (log_fd >= 0 && log_fd != STDERR_FILENO) {
        close(log_fd);
        log_fd = -1;
    }
}

static void coverage_signal_handler(int signo) {
    (void)signo;
    coverage_log("SIGUSR1 received, flushing coverage data");
    int ret = __llvm_profile_write_file();
    coverage_log("__llvm_profile_write_file() returned %d", ret);
}

__attribute__((constructor))
static void coverage_init(void) {
    coverage_log("coverage_init() constructor called");

    const char *env_profile = getenv("LLVM_PROFILE_FILE");
    coverage_log("LLVM_PROFILE_FILE env = %s", env_profile ? env_profile : "(null)");

    const char *filename_before = __llvm_profile_get_filename();
    coverage_log("Profile filename BEFORE reinit: %s",
                 filename_before ? filename_before : "(null)");

    const char *profile_path = "/helper-rust/coverage/helper-%p-%m.profraw";
    coverage_log("Setting LLVM_PROFILE_FILE to: %s", profile_path);
    setenv("LLVM_PROFILE_FILE", profile_path, 1);

    coverage_log("Calling __llvm_profile_initialize_file()");
    __llvm_profile_initialize_file();

    const char *filename_after = __llvm_profile_get_filename();
    coverage_log("Profile filename AFTER reinit: %s",
                 filename_after ? filename_after : "(null)");

    signal(SIGUSR1, coverage_signal_handler);
    coverage_log("Signal handler installed for SIGUSR1");

    atexit(coverage_atexit);
    coverage_log("Registered atexit handler");

    coverage_log("coverage_init() completed");
}

__attribute__((destructor))
static void coverage_fini(void) {
    coverage_log("coverage_fini() destructor called");
    coverage_flush();
    if (log_fd >= 0 && log_fd != STDERR_FILENO) {
        close(log_fd);
        log_fd = -1;
    }
}

#endif // COVERAGE_BUILD
