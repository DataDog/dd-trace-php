#define _GNU_SOURCE
#include <dirent.h>
#include <dlfcn.h>
#include <errno.h>
#include <fcntl.h>
#include <pthread.h>
#include <stdatomic.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

static pid_t php_pid;
static pthread_t php_thread;
static char *exit_path;
static int error_fd = STDERR_FILENO;
static atomic_int exiting;
static atomic_int background_dlclose;

static void check_reapers_at_exit(void);
static int thread_count(void);
static void mark_exit(const char *state);
static void fail(const char *message);

void *dlopen(const char *filename, int flags) {
    // PHP's DEEPBIND would otherwise hide the loader's calls from our dlclose hook.
    if (filename) {
        const char *name = strrchr(filename, '/');
        if (!strcmp(name ? name + 1 : filename, "dd_library_loader.so")) {
            flags &= ~RTLD_DEEPBIND;
        }
    }
    void *(*open_library)(const char *, int) =
        (void *(*)(const char *, int))dlsym(RTLD_NEXT, "dlopen");
    if (!open_library) fail("Cannot resolve libc dlopen\n");
    return open_library(filename, flags);
}

int dlclose(void *handle) {
    if (getpid() == php_pid && atomic_load(&exiting) &&
        !pthread_equal(pthread_self(), php_thread)) {
        // Observe the unsafe overlap without letting it corrupt destructor state.
        atomic_store(&background_dlclose, 1);
        return 0;
    }
    int (*close_library)(void *) = (int (*)(void *))dlsym(RTLD_NEXT, "dlclose");
    if (!close_library) fail("Cannot resolve libc dlclose\n");
    return close_library(handle);
}

__attribute__((constructor)) static void register_exit_check(void) {
    const char *expected_pid = getenv("DD_REAPER_TEST_PID");
    // The forwarder inherits LD_PRELOAD, but only the PHP process owns this check.
    if (!expected_pid || getpid() != (pid_t)strtol(expected_pid, NULL, 10)) return;
    php_pid = getpid();
    php_thread = pthread_self();
    // PHP may close its standard streams before this native exit handler runs.
    int saved_error_fd = fcntl(STDERR_FILENO, F_DUPFD_CLOEXEC, 3);
    if (saved_error_fd < 0) fail("Cannot preserve shutdown diagnostics\n");
    error_fd = saved_error_fd;
    // PHP can tear down its environment before libc starts running exit handlers.
    const char *path = getenv("DD_REAPER_TEST_EXIT_PATH");
    if (!path || !(exit_path = strdup(path))) fail("Cannot save exit marker path\n");
    if (atexit(check_reapers_at_exit)) fail("Cannot register exit check\n");
}

static void check_reapers_at_exit(void) {
    if (getpid() != php_pid) return;
    if (thread_count() <= 1) fail("No telemetry reapers active at process exit\n");

    // PHP has shut down its modules. Tell the test it can release the forwarders,
    // and keep this real exit handler active until their reaper threads finish.
    atomic_store(&exiting, 1);
    mark_exit("entered\n");
    struct timespec start, current;
    if (clock_gettime(CLOCK_MONOTONIC, &start)) fail("Cannot read clock\n");
    // /proc task enumeration and waitid are separate observations. Require both
    // invariants within the same deadline, and retry interrupted child queries.
    int threads, child_result, child_error;
    siginfo_t child;
    for (;;) {
        threads = thread_count();
        memset(&child, 0, sizeof(child));
        child_result = waitid(P_ALL, 0, &child, WEXITED | WNOHANG | WNOWAIT);
        child_error = child_result == -1 ? errno : 0;
        if (threads == 1 && child_result == -1 && child_error == ECHILD) break;
        if (child_result == -1 && child_error != ECHILD && child_error != EINTR) {
            fail("Cannot inspect telemetry children\n");
        }
        if (clock_gettime(CLOCK_MONOTONIC, &current)) fail("Cannot read clock\n");
        if (current.tv_sec - start.tv_sec > 5 ||
            (current.tv_sec - start.tv_sec == 5 && current.tv_nsec >= start.tv_nsec)) {
            char diagnostic[192];
            snprintf(diagnostic, sizeof(diagnostic),
                     "Telemetry shutdown did not finish: threads=%d, child=%ld, waitid=%d, errno=%d\n",
                     threads, (long)child.si_pid, child_result, child_error);
            fail(diagnostic);
        }
        struct timespec delay = {0, 1000000};
        nanosleep(&delay, NULL);
    }
    if (atomic_load(&background_dlclose)) {
        fail("Telemetry reaper called dlclose while an atexit handler was running\n");
    }
    mark_exit("passed\n");
    free(exit_path);
}

static int thread_count(void) {
    DIR *tasks = opendir("/proc/self/task");
    if (!tasks) fail("Cannot inspect PHP threads\n");
    int count = 0;
    struct dirent *task;
    while ((task = readdir(tasks))) {
        if (task->d_name[0] != '.') ++count;
    }
    closedir(tasks);
    return count;
}

static void mark_exit(const char *state) {
    int fd = open(exit_path, O_WRONLY | O_CREAT | O_TRUNC, 0600);
    if (fd < 0) fail("Cannot open exit marker\n");
    size_t length = strlen(state);
    if (write(fd, state, length) != (ssize_t)length) fail("Cannot write exit marker\n");
    if (close(fd)) fail("Cannot close exit marker\n");
}

static void fail(const char *message) {
    ssize_t written = write(error_fd, message, strlen(message));
    (void)written;
    _exit(86);
}
