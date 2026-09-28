// Note: Not included on Windows
#include "signals.h"
#include "crashtracking_frames.h"

#ifdef HAVE_CONFIG_H
#include <config.h>
#endif

#include <php_config.h>

#if HAVE_SIGACTION

#include <dogstatsd_client/client.h>
#include <php.h>
#include <signal.h>
#include <unistd.h>  // getpid / geteuid for crashtracker role selection

#include "configuration.h"
#include "datadog.h"
#include "ffi_utils.h"
#include "sidecar.h"
#include "threads.h"
#include "version.h"
#include <components/log/log.h>
#include "logging.h"
#undef datadog_signal_safe_logf

#include <components-rs/common.h>
#include <components-rs/datadog.h>
#include <components-rs/crashtracker.h>
#include <components-rs/sidecar.h>

#if PHP_VERSION_ID >= 80000
#include <SAPI.h>
#include <Zend/zend_extensions.h>
#endif

#if defined HAVE_EXECINFO_H && defined backtrace_size_t && defined HAVE_BACKTRACE
#define DATADOG_HAVE_BACKTRACE 1
#else
#define DATADOG_HAVE_BACKTRACE 0
#endif

#if DATADOG_HAVE_BACKTRACE
#include <execinfo.h>
#endif

#if __linux
#include <errno.h>
#include <linux/futex.h>
#include <sched.h>
#include <stdatomic.h>
#include <sys/syscall.h>
#include <unistd.h>
#endif

#define MAX_STACK_SIZE 1024
// Leave room for the backtrace array, logging, libc calls, and the signal frame.
#define MIN_STACKSZ (64 * 1024)

// true globals; only modify in MINIT/MSHUTDOWN
static stack_t dd_altstack;
static struct sigaction dd_sigsegv_sigaction;
static char *dd_signal_async_stack;
static size_t dd_signal_async_stack_size;

#if DATADOG_HAVE_BACKTRACE
typedef backtrace_size_t dd_backtrace_size_t;

static dd_backtrace_size_t dd_backtrace(void **array, dd_backtrace_size_t size) {
    return backtrace(array, size);
}

static char **dd_backtrace_symbols(void *const *array, dd_backtrace_size_t size) {
    return backtrace_symbols(array, size);
}

static bool dd_backtrace_is_available(void) {
    return true;
}
#else
typedef int dd_backtrace_size_t;

// Portable extensions are built against musl, which does not provide
// execinfo.h. Weak imports keep backtraces available on glibc without adding a
// musl libexecinfo dependency.
extern dd_backtrace_size_t backtrace(void **, dd_backtrace_size_t) __attribute__((weak));
extern char **backtrace_symbols(void *const *, dd_backtrace_size_t) __attribute__((weak));

static dd_backtrace_size_t dd_backtrace(void **array, dd_backtrace_size_t size) {
    return backtrace(array, size);
}

static char **dd_backtrace_symbols(void *const *array, dd_backtrace_size_t size) {
    return backtrace_symbols(array, size);
}

static bool dd_backtrace_is_available(void) {
    return backtrace && backtrace_symbols;
}
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

static void dd_sigsegv_handler(int sig) {
    if (!DATADOG_G(backtrace_handler_already_run)) {
        DATADOG_G(backtrace_handler_already_run) = true;
        datadog_signal_safe_logf("[crash] Segmentation fault encountered");

#if HAVE_SIGACTION && defined(DDTRACE)
        bool health_metrics_enabled = get_DD_TRACE_HEALTH_METRICS_ENABLED();
        if (health_metrics_enabled) {
            // TODO: emit in sidecar
            dogstatsd_client *client = &DDTRACE_G(dogstatsd_client);
            const char *metric = "datadog.tracer.uncaught_exceptions";
            const char *tags = "class:sigsegv";
            dogstatsd_client_status status = dogstatsd_client_count(client, metric, "1", tags);

            if (status == DOGSTATSD_CLIENT_OK) {
                datadog_signal_safe_logf("[crash] sigsegv health metric sent");
            }
        }
#endif

        if (dd_backtrace_is_available()) {
            datadog_signal_safe_logf("Datadog PHP Trace extension (DEBUG MODE)");
            datadog_signal_safe_logf("Received Signal %d", sig);
            void *array[MAX_STACK_SIZE];
            dd_backtrace_size_t size = dd_backtrace(array, MAX_STACK_SIZE);
            if (size == MAX_STACK_SIZE) {
                datadog_signal_safe_logf("Note: max stacktrace size reached");
            }

            datadog_signal_safe_logf("Note: Backtrace below might be incomplete and have wrong entries due to optimized runtime");
            datadog_signal_safe_logf("Backtrace:");

            char **backtraces = dd_backtrace_symbols(array, size);
            if (backtraces) {
                for (dd_backtrace_size_t i = 0; i < size; i++) {
                    ddog_log_callback((ddog_CharSlice){ .ptr = backtraces[i], .len = strlen(backtraces[i]) });
                }
                free(backtraces);
            }
        }
    }

    int error_log_fd = atomic_load(&datadog_error_log_fd);
    if (error_log_fd != -1) {
#ifndef _WIN32
        fsync(error_log_fd);
#else
        _commit(error_log_fd);
#endif
    }

    // _Exit to avoid atexit() handlers, they may crash in this SIGSEGV signal handler...
    _Exit(128 + sig);
}

#if PHP_VERSION_ID >= 80000
static zend_never_inline ZEND_COLD void dd_crasht_failed_tag_push(
    ddog_Error *err,
    ddog_CharSlice key
) {
    ddog_CharSlice msg = ddog_Error_message(err);
    LOG(DEBUG,
        "Failed to push tag \"%.*s\": %.*s",
        (int) key.len, key.ptr,
        (int) msg.len, msg.ptr);
    ddog_Error_drop(err);
}

// Pushes a tag and logs a failure.
static zend_always_inline void dd_crasht_push_tag(
    ddog_Vec_Tag *tags,
    ddog_CharSlice key,
    ddog_CharSlice val
) {
    ddog_Vec_Tag_PushResult result = ddog_Vec_Tag_push(tags, key, val);
    if (UNEXPECTED(result.tag != DDOG_VEC_TAG_PUSH_RESULT_OK)) {
        dd_crasht_failed_tag_push(&result.err, key);
    }
}

static zend_always_inline zend_string *dd_crasht_find_ini_by_tag(ddog_CharSlice tag) {
    const ddog_CharSlice PREFIX = DDOG_CHARSLICE_C("php.");
    ZEND_ASSERT(tag.len > PREFIX.len && memcmp(tag.ptr, PREFIX.ptr, PREFIX.len) == 0);

    ddog_CharSlice ini = { .ptr = tag.ptr + PREFIX.len, .len = tag.len - PREFIX.len };
    zend_string *value = zend_ini_str(ini.ptr, ini.len, false);
    // On PHP 8.0+ these INI should all exist, but guard against the NULL case
    // in case something goes wrong, or this changes in a future version.
    if (UNEXPECTED(!value)) {
        LOG(TRACE,
            "crashtracker setup: INI \"%.*s\" not found (maybe compiled out in this PHP build)",
            (int) ini.len, ini.ptr);
    }
    return value;
}

// Pass in a key like "php.opcache.enable" and "php." will get stripped off,
// and that's what the INI setting will be.
static void dd_crasht_add_ini_by_tag(ddog_Vec_Tag *tags, ddog_CharSlice key) {
    zend_string *value = dd_crasht_find_ini_by_tag(key);
    if (EXPECTED(value)) {
        dd_crasht_push_tag(tags, key, dd_zend_string_to_CharSlice(value));
    }
}
#endif

const ddog_CharSlice ZERO = DDOG_CHARSLICE_C("0");
const ddog_CharSlice ONE = DDOG_CHARSLICE_C("1");
const ddog_CharSlice PHP_OPCACHE_ENABLE = DDOG_CHARSLICE_C("php.opcache.enable");

// Fetches certain opcache tags and adds them with the pattern of php.opcache.*.
static void dd_crasht_add_opcache_inis(ddog_Vec_Tag *tags) {
#if PHP_VERSION_ID >= 80000
    bool loaded = zend_get_extension("Zend OPcache");
    if (UNEXPECTED(!loaded)) {
        goto opcache_disabled;
    }

    // The CLI SAPI has an additional configuration for being enabled. This is
    // INI_SYSTEM so we can check it here.
    bool is_cli_sapi = strcmp("cli", sapi_module.name) == 0;
    if (is_cli_sapi) {
        ddog_CharSlice tag = DDOG_CHARSLICE_C("php.opcache.enable_cli");
        zend_string *value = dd_crasht_find_ini_by_tag(tag);
        if (EXPECTED(value)) {
            bool is_enabled = zend_ini_parse_bool(value);
            ddog_CharSlice val = is_enabled ? ONE : ZERO;
            dd_crasht_push_tag(tags, tag, val);
        }
    }

    // opcache.jit_buffer_size is INI_SYSTEM, so we can check it now. If it's
    // zero, then JIT won't operate.
    {
        ddog_CharSlice tag = DDOG_CHARSLICE_C("php.opcache.jit_buffer_size");
        zend_string *value = dd_crasht_find_ini_by_tag(tag);
        if (EXPECTED(value)) {
            // Parse the quantity similarly to OnUpdateLong.
#if PHP_VERSION_ID >= 80200
            zend_string *errstr = NULL;
            zend_long quantity = zend_ini_parse_quantity(value, &errstr);
            if (errstr) zend_string_release(errstr);
#else
            zend_long quantity = zend_atol(ZSTR_VAL(value), ZSTR_LEN(value));
#endif
            bool is_positive = quantity > 0;
            ddog_CharSlice val = is_positive
                ? dd_zend_string_to_CharSlice(value)
                : ZERO;
            dd_crasht_push_tag(tags, tag, val);
        }
    }

    // The others are INI_ALL, so it's possible that they change at runtime.
    dd_crasht_add_ini_by_tag(tags, PHP_OPCACHE_ENABLE);
    dd_crasht_add_ini_by_tag(tags, DDOG_CHARSLICE_C("php.opcache.jit"));
    return;

opcache_disabled:
    dd_crasht_push_tag(tags, PHP_OPCACHE_ENABLE, ZERO);
#else
    (void)tags;
#endif
}

static void dd_init_crashtracker() {
    if (!datadog_endpoint) {
        return;
    }

    ddog_Vec_Tag tags = ddog_Vec_Tag_new();
    dd_crasht_add_opcache_inis(&tags);
    ddog_crasht_Metadata metadata = datadog_setup_crashtracking_metadata(&tags);

    int32_t master_pid = datadog_sidecar_active_mode == DD_SIDECAR_CONNECTION_THREAD ? datadog_sidecar_master_pid : 0;
    datadog_ffi_try("Cannot initialize CrashTracker",
                    datadog_crashtracker_init(datadog_endpoint, metadata, master_pid));

    datadog_register_crashtracking_frames_collection();

    ddog_Vec_Tag_drop(tags);
}

static void dd_signals_init_async_stack() {
    if (!dd_signal_async_stack) {
        dd_signal_async_stack_size = SIGSTKSZ < MIN_STACKSZ ? MIN_STACKSZ : SIGSTKSZ;
        dd_signal_async_stack = malloc(dd_signal_async_stack_size);
    }
}

void datadog_signals_first_rinit(void) {
    DATADOG_G(backtrace_handler_already_run) = false;

    // Signal handlers are causing issues with FrankenPHP.
    if (datadog_active_sapi == DATADOG_PHP_SAPI_FRANKENPHP) {
        return;
    }

    bool install_crashtracker = get_DD_INSTRUMENTATION_TELEMETRY_ENABLED() && get_DD_CRASHTRACKING_ENABLED();

    bool health_metrics = get_DD_TRACE_HEALTH_METRICS_ENABLED();
    bool log_backtrace = get_DD_LOG_BACKTRACE();

    if (log_backtrace && !dd_backtrace_is_available()) {
        LOG(WARN, "Setting 'datadog.log_backtrace' is not supported on this platform, as backtrace() is unavailable (e.g. on musl). Ignoring it.");
        log_backtrace = false;
    }

    if (install_crashtracker) {
        dd_init_crashtracker();
    }

    if (!log_backtrace && !health_metrics) {
        return;
    }

    if (install_crashtracker) {
        if (log_backtrace) {
            LOG(WARN, "Settings 'datadog.log_backtrace' and 'datadog.crashtracking_enabled' are mutually exclusive. Cannot enable the backtrace.");
        } else {
            LOG(WARN, "Segmentation faults will not be reported as the 'datadog.tracer.uncaught_exceptions' health metric while 'datadog.crashtracking_enabled' is on.");
        }
        return;
    }

    /* Install a signal handler for SIGSEGV and run it on an alternate stack.
     * Using an alternate stack allows the handler to run even when the main
     * stack overflows.
     */
    dd_signals_init_async_stack();

    dd_altstack.ss_sp = dd_signal_async_stack;
    dd_altstack.ss_size = dd_signal_async_stack_size;
    dd_altstack.ss_flags = 0;
    if (sigaltstack(&dd_altstack, NULL) == 0) {
        dd_sigsegv_sigaction.sa_flags = SA_ONSTACK;
        dd_sigsegv_sigaction.sa_handler = dd_sigsegv_handler;
        sigemptyset(&dd_sigsegv_sigaction.sa_mask);
        sigaction(SIGSEGV, &dd_sigsegv_sigaction, NULL);
    }
}

#if __linux
static struct sigaction dd_sigint_sigterm_sigaction;
static struct sigaction dd_sigterm_prev_sigaction;
static struct sigaction dd_sigint_prev_sigaction;

// The request is prepared in ordinary context. Publication, signal delivery,
// and shutdown compete for this gate; a claimed request is never replaced or rearmed.
enum {
    DD_SIGNAL_DISABLED,
    DD_SIGNAL_INSTALLING,
    DD_SIGNAL_READY,
    DD_SIGNAL_FLUSHING_DEFAULT,
    DD_SIGNAL_FLUSHING_CUSTOM,
    DD_SIGNAL_STOPPED,
};
static _Atomic(int) dd_signal_state;
static _Atomic(int) dd_signal_owner_pid;
static ddog_SignalFlush *dd_signal_flush;
// Only one worker can use this stack. Its storage lives as long as the extension.
static _Alignas(16) char dd_signal_cleanup_stack[MIN_STACKSZ];

// Before READY is published: -1 (not started). clone's PARENT_SETTID writes a positive
// TID; CHILD_CLEARTID clears it and wakes futex waiters when the worker has fully exited.
// A failed start clears it explicitly. This also covers a child exiting before clone returns.
static _Atomic(int) dd_signal_worker_tid;
_Static_assert(ATOMIC_INT_LOCK_FREE == 2, "signal atomics must be lock-free");
_Static_assert(sizeof(dd_signal_worker_tid) == sizeof(int), "signal TID must have the kernel int layout");

bool datadog_signals_has_sidecar_flush(void) {
    return atomic_load(&dd_signal_state) != DD_SIGNAL_DISABLED;
}

void datadog_signals_set_sidecar_flush(ddog_SignalFlush *flush, bool replace) {
    if (!flush && !replace) {
        return;
    }
    // A handler must not suspend its own publisher while INSTALLING. Other threads
    // may spin on that state, so the protected section must contain no blocking calls.
    sigset_t publication_signals, old_signals;
    sigfillset(&publication_signals);
    if (sigprocmask(SIG_BLOCK, &publication_signals, &old_signals) < 0) {
        ddog_sidecar_signal_flush_drop(flush);
        return;
    }
    int state = atomic_load(&dd_signal_state);
    if ((state == DD_SIGNAL_DISABLED || (replace && state == DD_SIGNAL_READY)) &&
        atomic_compare_exchange_strong(&dd_signal_state, &state, DD_SIGNAL_INSTALLING)) {
        ddog_SignalFlush *previous = dd_signal_flush;
        dd_signal_flush = flush;
        atomic_store(&dd_signal_worker_tid, -1);
        atomic_store(&dd_signal_state, flush ? DD_SIGNAL_READY : DD_SIGNAL_DISABLED);
        // Drop AFTER publication: another thread's handler may have interrupted malloc.
        flush = previous;
    }
    ddog_sidecar_signal_flush_drop(flush);
    sigprocmask(SIG_SETMASK, &old_signals, NULL);
}

void datadog_signals_reset_sidecar_flush_after_fork(void) {
    // The old PID makes handlers ignore inherited state until this reset is complete.
    ddog_sidecar_signal_flush_drop(dd_signal_flush);
    dd_signal_flush = NULL;
    atomic_store(&dd_signal_worker_tid, 0);
    atomic_store(&dd_signal_state, DD_SIGNAL_DISABLED);
    atomic_store(&dd_signal_owner_pid, getpid());
}

static void dd_signals_drop_sidecar_flush(void) {
    // A signal may cause clean shutdown in a fork child before its PHP fork hook runs.
    if (atomic_load(&dd_signal_owner_pid) != getpid()) {
        datadog_signals_reset_sidecar_flush_after_fork();
    }
    for (;;) {
        int state = atomic_load(&dd_signal_state);
        if (state == DD_SIGNAL_INSTALLING) {
            sched_yield();
            continue;
        }
        if (state == DD_SIGNAL_FLUSHING_DEFAULT || state == DD_SIGNAL_FLUSHING_CUSTOM) {
            int tid;
            while ((tid = atomic_load(&dd_signal_worker_tid)) != 0) {
                if (tid == -1) {
                    sched_yield(); // The handler has claimed the request but not finished clone.
                } else {
                    // Kernel clear_child_tid uses a shared futex. Reload after EINTR/EAGAIN
                    // or a spurious wake; only zero proves the request and extension can be released.
                    syscall(SYS_futex, &dd_signal_worker_tid, FUTEX_WAIT, tid, NULL, NULL, 0);
                }
            }
        }
        if (atomic_compare_exchange_strong(&dd_signal_state, &state, DD_SIGNAL_STOPPED)) {
            break;
        }
    }
    ddog_sidecar_signal_flush_drop(dd_signal_flush);
    dd_signal_flush = NULL;
}

// True means a default-disposition worker owns process termination. False means chain
// the previous handler, including when startup fails or a custom handler owns shutdown.
static bool dd_signals_start_flush(bool terminate) {
    if (getpid() != atomic_load(&dd_signal_owner_pid)) {
        return false;
    }
    int state = DD_SIGNAL_READY;
    int claimed = terminate ? DD_SIGNAL_FLUSHING_DEFAULT : DD_SIGNAL_FLUSHING_CUSTOM;
    while (!atomic_compare_exchange_strong(&dd_signal_state, &state, claimed)) {
        if (state != DD_SIGNAL_INSTALLING) {
            return terminate && state == DD_SIGNAL_FLUSHING_DEFAULT;
        }
        sched_yield();
        state = DD_SIGNAL_READY;
    }

    int flags = CLONE_VM | CLONE_FS | CLONE_FILES | CLONE_SIGHAND | CLONE_THREAD | CLONE_SYSVSEM |
                CLONE_PARENT_SETTID | CLONE_CHILD_CLEARTID;
    int result = datadog_clone_thread(datadog_sidecar_signal_flush_run, dd_signal_cleanup_stack + MIN_STACKSZ,
                                      flags, dd_signal_flush, terminate, &dd_signal_worker_tid);
    if (result < 0) {
        atomic_store(&dd_signal_worker_tid, 0);
    }
    return result >= 0 && terminate;
}

static void dd_sigint_sigterm_handler(int sig, siginfo_t *si, void *uc) {
    struct sigaction *previous = sig == SIGINT ? &dd_sigint_prev_sigaction : &dd_sigterm_prev_sigaction;
    if (previous->sa_handler == SIG_IGN) {
        return;
    }
    int saved_errno = errno;
    // Block signals before claiming the request: a nested handler must not enter PHP
    // shutdown while clone is starting. libc's mask APIs exclude reserved signals, so
    // use the kernel mask for the worker's inherited TLS. The PHP thread can use syscall().
    uint64_t all_signals = UINT64_MAX, old_signals;
    bool defer_termination = false;
    if (syscall(SYS_rt_sigprocmask, SIG_SETMASK, &all_signals, &old_signals, sizeof(all_signals)) == 0) {
        defer_termination = dd_signals_start_flush(previous->sa_handler == SIG_DFL);
        syscall(SYS_rt_sigprocmask, SIG_SETMASK, &old_signals, NULL, sizeof(old_signals));
    }
    errno = saved_errno;
    if (defer_termination) {
        return;
    }
    if (previous->sa_handler == SIG_DFL) {
        _exit(0);
    } else if (previous->sa_flags & SA_SIGINFO) {
        previous->sa_sigaction(sig, si, uc);
    } else {
        previous->sa_handler(sig);
    }
}
#endif

void datadog_signals_minit(void) {
#if __linux
    atomic_store(&dd_signal_owner_pid, getpid());
    dd_sigint_sigterm_sigaction.sa_sigaction = dd_sigint_sigterm_handler;
    dd_sigint_sigterm_sigaction.sa_flags = SA_SIGINFO;
    sigemptyset(&dd_sigint_sigterm_sigaction.sa_mask);
    if (get_global_DD_TRACE_FORCE_FLUSH_ON_SIGTERM()) {
        sigaction(SIGTERM, &dd_sigint_sigterm_sigaction, &dd_sigterm_prev_sigaction);
    }
    if (get_global_DD_TRACE_FORCE_FLUSH_ON_SIGINT()) {
        sigaction(SIGINT, &dd_sigint_sigterm_sigaction, &dd_sigint_prev_sigaction);
    }
#endif
}

void datadog_signals_mshutdown(void) {
#if __linux
    // wait for the signal cleanup thread to exit
    dd_signals_drop_sidecar_flush();
    if (dd_sigint_sigterm_sigaction.sa_sigaction) {
        if (get_global_DD_TRACE_FORCE_FLUSH_ON_SIGTERM()) {
            sigaction(SIGTERM, &dd_sigterm_prev_sigaction, NULL);
        }
        if (get_global_DD_TRACE_FORCE_FLUSH_ON_SIGINT()) {
            sigaction(SIGINT, &dd_sigint_prev_sigaction, NULL);
        }
    }
#endif

    if (dd_signal_async_stack) {
        free(dd_signal_async_stack);
        dd_signal_async_stack = NULL;
    }
}

#else
void datadog_signals_first_rinit(void) {}
void datadog_signals_mshutdown(void) {}
#endif

// This allows us to include the executing php binary and extensions themselves in the core dump too
void datadog_set_coredumpfilter(void) {
    FILE *fp = fopen("/proc/self/coredump_filter", "r+");
    if (!fp) {
        return;
    }

    // reading from that file returns a hex number, but to write it, it needs to be prefixed 0x, otherwise it's interpreted as octal
    char buf[10];
    if (fread(buf + 2, 8, 1, fp) != 8) {
        fclose(fp);
        return;
    }

    buf[0] = '0';
    buf[1] = 'x';
    // From core(5) man page:
    // bit 0  Dump anonymous private mappings.
    // bit 1  Dump anonymous shared mappings.
    // bit 2  Dump file-backed private mappings.
    // bit 3  Dump file-backed shared mappings.
    buf[9] = 'f';

    fseek(fp, 0, SEEK_SET);
    fwrite(buf, 10, 1, fp);
    fclose(fp);
}
