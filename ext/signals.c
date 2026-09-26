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

#if DATADOG_HAVE_BACKTRACE
        datadog_signal_safe_logf("Datadog PHP Trace extension (DEBUG MODE)");
        datadog_signal_safe_logf("Received Signal %d", sig);
        void *array[MAX_STACK_SIZE];
        backtrace_size_t size = backtrace(array, MAX_STACK_SIZE);
        if (size == MAX_STACK_SIZE) {
            datadog_signal_safe_logf("Note: max stacktrace size reached");
        }

        datadog_signal_safe_logf("Note: Backtrace below might be incomplete and have wrong entries due to optimized runtime");
        datadog_signal_safe_logf("Backtrace:");

        char **backtraces = backtrace_symbols(array, size);
        if (backtraces) {
            for (backtrace_size_t i = 0; i < size; i++) {
                ddog_log_callback((ddog_CharSlice){ .ptr = backtraces[i], .len = strlen(backtraces[i]) });
            }
            free(backtraces);
        }
#endif
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

    bool install_backtrace_handler = get_DD_TRACE_HEALTH_METRICS_ENABLED();
#if DATADOG_HAVE_BACKTRACE
    install_backtrace_handler |= get_DD_LOG_BACKTRACE();
#endif

    if (install_crashtracker) {
        dd_init_crashtracker();
    }

    /* Install a signal handler for SIGSEGV and run it on an alternate stack.
     * Using an alternate stack allows the handler to run even when the main
     * stack overflows.
     */
    if (install_backtrace_handler) {
        if (install_crashtracker) {
            LOG(WARN, "Settings 'datadog.log_backtrace' and 'datadog.crashtracking_enabled' are mutually exclusive. Cannot enable the backtrace.");
            return;
        }

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
}

#if __linux
static struct sigaction dd_sigint_sigterm_sigaction;
static struct sigaction dd_sigterm_prev_sigaction;
static struct sigaction dd_sigint_prev_sigaction;

// The cleanup stack and prepared flush object are allocated in ordinary
// context. Once READY is published, the signal handler only reads them and
// exactly one raw worker may use them.
static char *dd_signal_cleanup_stack;
static size_t dd_signal_cleanup_stack_size;
static ddog_SignalFlush *dd_signal_flush;
// The process ID, rather than a thread ID, lets every PHP thread in this process
// pass while rejecting a copy inherited by a fork child.
static _Atomic(int) dd_signal_owner_pid;
// CLONE_PARENT_SETTID writes the worker TID here. CLONE_CHILD_CLEARTID clears it
// and performs a futex wake at thread exit, giving normal shutdown a join
// primitive that does not depend on pthread state.
static _Atomic(int) dd_signal_worker_tid;

// The object is one-shot:
//
//   DISABLED -> INSTALLING -> READY -> STARTING_* -> RUNNING_* -> STOPPED
//                                  `-> FAILED -----------^
//
// INSTALLING protects publication from teardown. STARTING protects clone setup.
// The DEFAULT and CUSTOM variants preserve who is responsible for termination.
enum {
    DD_SIGNAL_DISABLED,          // No flush object is installed.
    DD_SIGNAL_INSTALLING,        // A flush object is being published.
    DD_SIGNAL_READY,             // One signal may claim the published object.
    DD_SIGNAL_STARTING_DEFAULT,  // clone() is in progress; the worker will terminate the process.
    DD_SIGNAL_STARTING_CUSTOM,   // clone() is in progress; the worker will return after flushing.
    DD_SIGNAL_RUNNING_DEFAULT,   // The TID is joinable; the worker will terminate the process.
    DD_SIGNAL_RUNNING_CUSTOM,    // The TID is joinable; the worker will return after flushing.
    DD_SIGNAL_FAILED,            // Worker creation failed; another signal may not retry it.
    DD_SIGNAL_STOPPED,           // Module shutdown has disabled new workers.
};
static _Atomic(int) dd_signal_state;
// Atomics used by a signal handler must compile to instructions; a libatomic
// fallback could lock or touch runtime state while the interrupted thread owns it.
// The TID object is also written directly by the kernel, whose clone/futex ABI
// requires the ordinary int representation.
_Static_assert(ATOMIC_INT_LOCK_FREE == 2, "signal atomics must be lock-free");
_Static_assert(sizeof(dd_signal_worker_tid) == sizeof(int), "signal TID must have the kernel int layout");

static void dd_signals_init_cleanup_stack(void);
static void dd_signals_drop_sidecar_flush(void);
static void dd_call_prev_handler(int sig, siginfo_t *si, void *uc);

bool datadog_signals_has_sidecar_flush(void) {
    // Any state other than DISABLED owns or is publishing an object. In
    // particular, FAILED and STOPPED must not allow a second publication.
    return atomic_load_explicit(&dd_signal_state, memory_order_acquire) != DD_SIGNAL_DISABLED;
}

void datadog_signals_set_sidecar_flush(ddog_SignalFlush *flush) {
    // Consume `flush`. On success it becomes the process-wide signal flush
    // object; if it cannot be installed, destroy it before returning.
    int expected = DD_SIGNAL_DISABLED;
    if (!flush) {
        return;
    }
    // prevent a previous handler from suspending this publication
    sigset_t publication_signals, old_signals;
    sigemptyset(&publication_signals);
    sigaddset(&publication_signals, SIGTERM);
    sigaddset(&publication_signals, SIGINT);
    if (sigprocmask(SIG_BLOCK, &publication_signals, &old_signals) < 0) {
        datadog_sidecar_signal_flush_drop(flush);
        return;
    }
    if (!atomic_compare_exchange_strong_explicit(&dd_signal_state, &expected, DD_SIGNAL_INSTALLING,
                                                 memory_order_acq_rel, memory_order_acquire)) {
        datadog_sidecar_signal_flush_drop(flush);
        sigprocmask(SIG_SETMASK, &old_signals, NULL);
        return;
    }
    dd_signal_flush = flush;
    atomic_store_explicit(&dd_signal_owner_pid, getpid(), memory_order_relaxed);
    // READY is the publication barrier for the pointer and owner PID. A handler
    // can acquire READY only after both values have been initialized.
    atomic_store_explicit(&dd_signal_state, DD_SIGNAL_READY, memory_order_release);
    // A pending SIGTERM/SIGINT may run as soon as the old mask is restored; at
    // that point it observes either the complete publication or a terminal state.
    sigprocmask(SIG_SETMASK, &old_signals, NULL);
}

void datadog_signals_reset_sidecar_flush_after_fork(void) {
    atomic_store_explicit(&dd_signal_worker_tid, 0, memory_order_relaxed);
    ddog_SignalFlush *flush = dd_signal_flush;
    dd_signal_flush = NULL;
    datadog_sidecar_signal_flush_drop(flush);
    atomic_store_explicit(&dd_signal_state, DD_SIGNAL_DISABLED, memory_order_release);
    // the owner pid is purposefully still the old one
}

static void dd_signals_drop_sidecar_flush(void) {
    // Called during module shutdown before freeing the flush object and stack.
    // Wait if the signal handler is creating a worker, and wait for an existing
    // worker to exit. Then set STOPPED so later signals use the previous handler.
    for (;;) {
        int state = atomic_load_explicit(&dd_signal_state, memory_order_acquire);
        if (state == DD_SIGNAL_INSTALLING || state == DD_SIGNAL_STARTING_DEFAULT ||
            state == DD_SIGNAL_STARTING_CUSTOM) {
            // Ordinary shutdown context. The signal handler performs only bounded
            // startup.
            sched_yield();
            continue;
        }
        if (state == DD_SIGNAL_RUNNING_DEFAULT || state == DD_SIGNAL_RUNNING_CUSTOM) {
            int tid;
            while ((tid = atomic_load_explicit(&dd_signal_worker_tid, memory_order_acquire)) != 0) {
                // FUTEX_WAIT sleeps only if the word still equals tid, closing
                // the exit-before-wait race. EAGAIN, EINTR, and spurious wakes
                // are harmless because the loop reloads the word. The kernel's
                // clear_child_tid wake uses a shared futex, not FUTEX_WAIT_PRIVATE.
                datadog_raw_syscall6(SYS_futex, (long)(uintptr_t)&dd_signal_worker_tid, FUTEX_WAIT, tid, 0, 0, 0);
            }
        }
        // A READY handler can race this transition. compare_exchange either
        // stops it before clone, or reports its newer STARTING/RUNNING state so
        // the loop waits for it above.
        if (atomic_compare_exchange_strong_explicit(&dd_signal_state, &state, DD_SIGNAL_STOPPED,
                                                    memory_order_acq_rel, memory_order_acquire)) {
            break;
        }
    }
    ddog_SignalFlush *flush = dd_signal_flush;
    dd_signal_flush = NULL;
    datadog_sidecar_signal_flush_drop(flush);
}

static void dd_call_prev_handler(int sig, siginfo_t *si, void *uc) {
    struct sigaction *prev_sigaction = sig == SIGINT ? &dd_sigint_prev_sigaction : &dd_sigterm_prev_sigaction;
    void *prev_handler = (prev_sigaction->sa_flags & SA_SIGINFO) ? (void *)prev_sigaction->sa_sigaction
                                                                 : (void *)prev_sigaction->sa_handler;

    if (prev_handler == SIG_IGN) {
        return;
    }
    if (prev_handler == SIG_DFL) {
        _exit(0);
    }
    if (prev_sigaction->sa_flags & SA_SIGINFO) {
        (*prev_sigaction->sa_sigaction)(sig, si, uc);
    } else {
        (*prev_sigaction->sa_handler)(sig);
    }
}

static void dd_sigint_sigterm_handler(int sig, siginfo_t *si, void *uc) {
    struct sigaction *prev_sigaction = sig == SIGINT ? &dd_sigint_prev_sigaction : &dd_sigterm_prev_sigaction;
    void *prev_handler = (prev_sigaction->sa_flags & SA_SIGINFO) ? (void *)prev_sigaction->sa_sigaction
                                                                 : (void *)prev_sigaction->sa_handler;
    if (prev_handler == SIG_IGN) {
        return;
    }
    bool terminate = prev_handler == SIG_DFL;
    int expected = DD_SIGNAL_READY;
    int starting = terminate ? DD_SIGNAL_STARTING_DEFAULT : DD_SIGNAL_STARTING_CUSTOM;
    // READY is consumed exactly once. Besides preventing duplicate workers, the
    // acquire half makes the prepared flush object and owner PID visible.
    if (!atomic_compare_exchange_strong_explicit(&dd_signal_state, &expected, starting, memory_order_acq_rel,
                                                 memory_order_acquire)) {
        // A worker handling a default-disposition signal will terminate the
        // process after flushing. Do not let another default signal iterrupt
        // the flush early by invoking the previous disposition.
        if (terminate && (expected == DD_SIGNAL_STARTING_DEFAULT || expected == DD_SIGNAL_RUNNING_DEFAULT)) {
            return;
        }
        dd_call_prev_handler(sig, si, uc);
        return;
    }

    // A fork child may inherit READY before its post-fork reset. Its owner PID
    // is still the parent's, so reject it before accessing the inherited flush
    // object.
    if (datadog_raw_syscall6(SYS_getpid, 0, 0, 0, 0, 0, 0) !=
        atomic_load_explicit(&dd_signal_owner_pid, memory_order_relaxed)) {
        atomic_store_explicit(&dd_signal_state, DD_SIGNAL_FAILED, memory_order_release);
        dd_call_prev_handler(sig, si, uc);
        return;
    }
    if (!dd_signal_cleanup_stack) {
        atomic_store_explicit(&dd_signal_state, DD_SIGNAL_FAILED, memory_order_release);
        dd_call_prev_handler(sig, si, uc);
        return;
    }

    // The handler's sa_mask already blocks ordinary blockable signals. glibc and
    // musl deliberately remove their internal signals from masks installed via
    // public libc APIs, however. If one reached the raw clone, its libc handler
    // could use the parent's inherited, unrepaired TLS. Bypass that filtering and
    // let the clone inherit the complete kernel mask before it can run. Doing
    // this inside the clone would leave a delivery window. SIGKILL and SIGSTOP
    // remain unblockable, but neither executes a user-space handler.
    uint64_t all_signals = UINT64_MAX, old_signals;
    if (datadog_raw_syscall6(SYS_rt_sigprocmask, SIG_SETMASK, (long)(uintptr_t)&all_signals,
                             (long)(uintptr_t)&old_signals, sizeof(all_signals), 0, 0) < 0) {
        atomic_store_explicit(&dd_signal_state, DD_SIGNAL_FAILED, memory_order_release);
        dd_call_prev_handler(sig, si, uc);
        return;
    }
    void *stack_top = dd_signal_cleanup_stack + dd_signal_cleanup_stack_size;
    // These are pthread-like sharing flags without CLONE_SETTLS: the worker has
    // no independent libc/Rust thread runtime and may execute only the audited
    // raw call graph. PARENT_SETTID plus CHILD_CLEARTID make
    // dd_signal_worker_tid a kernel-backed join word.
    int flags = CLONE_VM | CLONE_FS | CLONE_FILES | CLONE_SIGHAND | CLONE_THREAD | CLONE_SYSVSEM | CLONE_PARENT_SETTID |
                CLONE_CHILD_CLEARTID;
    // The trampoline copies `terminate` onto the child stack before clone. Rust
    // either returns for the custom-handler case or issues exit_group after the
    // bounded exchange for the default disposition.
    int result = datadog_clone_thread(datadog_sidecar_signal_flush_run, stack_top, flags, dd_signal_flush, terminate,
                                      &dd_signal_worker_tid);
    int running = terminate ? DD_SIGNAL_RUNNING_DEFAULT : DD_SIGNAL_RUNNING_CUSTOM;
    // The child may finish before clone returns. That is safe: clear_child_tid
    // will already be zero when normal teardown observes the RUNNING state.
    atomic_store_explicit(&dd_signal_state, result < 0 ? DD_SIGNAL_FAILED : running, memory_order_release);
    datadog_raw_syscall6(SYS_rt_sigprocmask, SIG_SETMASK, (long)(uintptr_t)&old_signals, 0, sizeof(old_signals), 0, 0);

    if (result < 0 || prev_handler != SIG_DFL) {
        dd_call_prev_handler(sig, si, uc);
    }  // else  the cleanup worker started and calling dd_call_prev_handler
       // would exit the process
}

static void dd_signals_init_cleanup_stack(void) {
    if (!dd_signal_cleanup_stack) {
        // Allocate before signal delivery. The one-shot state gate ensures that
        // no two workers ever share this stack.
        dd_signal_cleanup_stack_size = MIN_STACKSZ;
        dd_signal_cleanup_stack = malloc(dd_signal_cleanup_stack_size);
    }
}
#endif

void datadog_signals_minit(void) {
#if __linux
    dd_sigint_sigterm_sigaction.sa_sigaction = dd_sigint_sigterm_handler;
    dd_sigint_sigterm_sigaction.sa_flags = SA_SIGINFO;
    sigemptyset(&dd_sigint_sigterm_sigaction.sa_mask);
    if (get_global_DD_TRACE_FORCE_FLUSH_ON_SIGTERM()) {
        dd_signals_init_cleanup_stack();
        sigaction(SIGTERM, &dd_sigint_sigterm_sigaction, &dd_sigterm_prev_sigaction);
    }
    if (get_global_DD_TRACE_FORCE_FLUSH_ON_SIGINT()) {
        dd_signals_init_cleanup_stack();
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

    free(dd_signal_cleanup_stack);
    dd_signal_cleanup_stack = NULL;
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
