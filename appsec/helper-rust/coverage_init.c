// LLVM coverage profiling initialization for helper-rust
//
// The embedded sidecar calls this module explicitly from its entrypoint. It
// reinitializes the LLVM profiling runtime to use the correct output path.
// Every other process that loads the library is left pointing at the path the
// harness passes in LLVM_PROFILE_FILE, which is /dev/null: none of them runs
// helper-rust, so their profiles cover nothing that is reported on.
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
#include <limits.h>
#include <sys/stat.h>
#include <time.h>
#include <errno.h>

// LLVM profiling runtime API (from compiler-rt/lib/profile/)
extern void __llvm_profile_initialize_file(void);
extern void __llvm_profile_set_filename(const char *);
extern int __llvm_profile_write_file(void);
extern void __llvm_profile_reset_counters(void);
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

// Writing in merge mode adds the file's counters back into the in-process
// ones, so a process that writes more than once counts everything it has
// already written a second time and the totals double per flush. This process
// flushes on SIGUSR1 as well as at exit, and the runtime registers a writer of
// its own on top of that, so the counters have to be dropped once they are on
// disk.
static void coverage_flush(void) {
    coverage_log("coverage_flush() called, flushing coverage data");

    int ret = __llvm_profile_write_file();
    __llvm_profile_reset_counters();
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
    __llvm_profile_reset_counters();
    coverage_log("__llvm_profile_write_file() returned %d", ret);
}

// %m selects merge mode, where the runtime serializes on an fcntl write lock
// and accumulates into one shared file per instrumented binary instead of
// writing one file per process. The runtime creates that file itself, at the
// first write, with open(..., 0666); the container's 022 umask turns it into
// 0644. Every container in a run shares the file, and a sidecar inherits the
// user of the SAPI that spawned it, so whoever writes first would otherwise
// end up owning a file the other users cannot open. Get there first and create
// it with the bits the umask would have dropped, after which the runtime only
// ever opens a file that everyone can already write. A zero-length file is
// what merge mode expects to find when there is nothing to merge yet.
//
// Only the runtime can expand the name, since %m stands for a hash of the
// instrumented binary, hence __llvm_profile_get_filename() rather than a path
// built by the caller.
//
// Created under a private name and hard-linked into place, so the file is
// never reachable under its final name while it is still 0644; link() fails
// with EEXIST if another process got there first, which is the wanted
// create-only-if-absent. The staging name deliberately does not end in
// .profraw, so a leftover cannot reach the coverage report.
static void coverage_precreate_profile_file(void) {
    const char *filename = __llvm_profile_get_filename();
    if (!filename || !filename[0]) {
        return;
    }

    char staging[PATH_MAX];
    int len = snprintf(staging, sizeof(staging), "%s.new.%d", filename,
                       (int)getpid());
    if (len < 0 || len >= (int)sizeof(staging)) {
        return;
    }

    int fd = open(staging, O_RDWR | O_CREAT | O_EXCL, 0666);
    if (fd < 0) {
        return;
    }
    if (fchmod(fd, 0666) != 0) {
        coverage_log("Failed to make %s group/other-writable: %s", staging,
                     strerror(errno));
    } else if (link(staging, filename) != 0 && errno != EEXIST) {
        coverage_log("Failed to create %s: %s", filename, strerror(errno));
    }
    close(fd);
    unlink(staging);
}

void ddappsec_helper_coverage_init(void) {
    coverage_log("ddappsec_helper_coverage_init() called");

    // Hardcoded rather than read from the environment on purpose. Only about
    // a third of the sidecars in a run inherit the ambient environment at all,
    // so a variable naming this path would reach some of them and not others,
    // and pointing it anywhere else would split the merge pool in two instead
    // of moving it. %m rather than %p so that repeatedly respawned sidecars
    // keep merging into the one file.
    const char *profile_path = "/helper-rust/helper-%m.profraw";
    coverage_log("Helper coverage profile = %s", profile_path);

    const char *filename_before = __llvm_profile_get_filename();
    coverage_log("Profile filename BEFORE reinit: %s",
                 filename_before ? filename_before : "(null)");

    __llvm_profile_set_filename(profile_path);
    coverage_log("Calling __llvm_profile_initialize_file()");
    __llvm_profile_initialize_file();

    const char *filename_after = __llvm_profile_get_filename();
    coverage_log("Profile filename AFTER reinit: %s",
                 filename_after ? filename_after : "(null)");

    coverage_precreate_profile_file();

    signal(SIGUSR1, coverage_signal_handler);
    coverage_log("Signal handler installed for SIGUSR1");

    atexit(coverage_atexit);
    coverage_log("Registered atexit handler");

    coverage_log("ddappsec_helper_coverage_init() completed");
}

#endif // COVERAGE_BUILD
