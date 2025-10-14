// Note: Not included on Windows
#include "signals.h"

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_config.h"

#if HAVE_SIGACTION

#include <dogstatsd_client/client.h>
#include <php.h>
#include <signal.h>

#include "configuration.h"
#include "ddtrace.h"
#include "sidecar.h"
#include "auto_flush.h"
#include "ext/version.h"
#include <components/log/log.h>
#include "logging.h"
#undef ddtrace_bgs_logf

#include <components-rs/common.h>
#include <components-rs/ddtrace.h>
#include <components-rs/crashtracker.h>

#if PHP_VERSION_ID >= 80000
#include <SAPI.h>
#include <Zend/zend_extensions.h>
#endif

#if defined HAVE_EXECINFO_H && defined backtrace_size_t && defined HAVE_BACKTRACE
#define DDTRACE_HAVE_BACKTRACE 1
#else
#define DDTRACE_HAVE_BACKTRACE 0
#endif

#if DDTRACE_HAVE_BACKTRACE
#include <execinfo.h>
#endif

#if __linux
#include <sched.h>
#include <unistd.h>
#endif

#define MAX_STACK_SIZE 1024
#define MIN_STACKSZ 16384  // enough to hold void *array[MAX_STACK_SIZE] plus a couple kilobytes

// true globals; only modify in MINIT/MSHUTDOWN
static stack_t dd_altstack;
static struct sigaction dd_sigsegv_sigaction;
static char crashtracker_socket_path[100] = {0};
static char *dd_signal_async_stack;
static size_t dd_signal_async_stack_size;

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static void dd_sigsegv_handler(int sig) {
    if (!DDTRACE_G(backtrace_handler_already_run)) {
        DDTRACE_G(backtrace_handler_already_run) = true;
        ddtrace_bgs_logf("[crash] Segmentation fault encountered");

#if HAVE_SIGACTION
        bool health_metrics_enabled = get_DD_TRACE_HEALTH_METRICS_ENABLED();
        if (health_metrics_enabled) {
            dogstatsd_client *client = &DDTRACE_G(dogstatsd_client);
            const char *metric = "datadog.tracer.uncaught_exceptions";
            const char *tags = "class:sigsegv";
            dogstatsd_client_status status = dogstatsd_client_count(client, metric, "1", tags);

            if (status == DOGSTATSD_CLIENT_OK) {
                ddtrace_bgs_logf("[crash] sigsegv health metric sent");
            }
        }
#endif

#if DDTRACE_HAVE_BACKTRACE
        ddtrace_bgs_logf("Datadog PHP Trace extension (DEBUG MODE)");
        ddtrace_bgs_logf("Received Signal %d", sig);
        void *array[MAX_STACK_SIZE];
        backtrace_size_t size = backtrace(array, MAX_STACK_SIZE);
        if (size == MAX_STACK_SIZE) {
            ddtrace_bgs_logf("Note: max stacktrace size reached");
        }

        ddtrace_bgs_logf("Note: Backtrace below might be incomplete and have wrong entries due to optimized runtime");
        ddtrace_bgs_logf("Backtrace:");

        char **backtraces = backtrace_symbols(array, size);
        if (backtraces) {
            for (backtrace_size_t i = 0; i < size; i++) {
                ddog_log_callback((ddog_CharSlice){ .ptr = backtraces[i], .len = strlen(backtraces[i]) });
            }
            free(backtraces);
        }
#endif
    }

    // _Exit to avoid atexit() handlers, they may crash in this SIGSEGV signal handler...
    _Exit(128 + sig);
}

static bool dd_crashtracker_check_result(ddog_VoidResult result, const char *msg) {
    if (result.tag != DDOG_VOID_RESULT_OK) {
        ddog_CharSlice error_msg = ddog_Error_message(&result.err);
        LOG(ERROR, "%s : %.*s", msg, (int) error_msg.len, error_msg.ptr);
        ddog_Error_drop(&result.err);
        return false;
    }

    return true;
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
        LOG(WARN,
            "crashtracker setup failed to find INI \"%.*s\"--is it removed in a newer version?",
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
    // We'll push php.opcache.enabled:0 whenever we detect we're disabled.

    bool loaded = zend_get_extension("Zend OPcache");
    if (UNEXPECTED(!loaded)) {
        goto opcache_disabled;
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
            if (UNEXPECTED(!is_positive)) {
                goto opcache_disabled;
            }
        }
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
            if (UNEXPECTED(!is_enabled)) {
                goto opcache_disabled;
            }
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
    ddog_CharSlice socket_path = ddog_sidecar_get_crashtracker_unix_socket_path();
    if (socket_path.len > sizeof(crashtracker_socket_path) - 1) {
        LOG(ERROR, "Cannot initialize CrashTracker : the socket path is too long.");
        free((void *) socket_path.ptr);
        return;
    }

    // Copy the string to a global buffer to avoid a use-after-free error
    memcpy(crashtracker_socket_path, socket_path.ptr, socket_path.len);
    crashtracker_socket_path[socket_path.len] = '\0';
    free((void *) socket_path.ptr);
    socket_path.ptr = crashtracker_socket_path;

    ddog_Endpoint *agent_endpoint = ddtrace_sidecar_agent_endpoint();
    if (!agent_endpoint) {
        return;
    }

    ddog_crasht_Config config = {
        .endpoint = agent_endpoint,
        .timeout_ms = 5000,
        .resolve_frames = DDOG_CRASHT_STACKTRACE_COLLECTION_ENABLED_WITH_INPROCESS_SYMBOLS,
        .optional_unix_socket_filename = socket_path,
        .additional_files = {0},
    };

    ddog_Vec_Tag tags = ddog_Vec_Tag_new();
    dd_crasht_add_opcache_inis(&tags);

    const ddog_crasht_Metadata metadata = ddtrace_setup_crashtracking_metadata(&tags);

    dd_crashtracker_check_result(
            ddog_crasht_init_without_receiver(
                    config,
                    metadata
            ),
            "Cannot initialize CrashTracker"
    );

    ddog_endpoint_drop(agent_endpoint);
    ddog_Vec_Tag_drop(tags);
}

static void dd_signals_init_async_stack() {
    if (!dd_signal_async_stack) {
        dd_signal_async_stack_size = SIGSTKSZ < MIN_STACKSZ ? MIN_STACKSZ : SIGSTKSZ;
        dd_signal_async_stack = malloc(dd_signal_async_stack_size);
    }
}

void ddtrace_signals_first_rinit(void) {
    DDTRACE_G(backtrace_handler_already_run) = false;

    // Signal handlers are causing issues with FrankenPHP.
    if (ddtrace_active_sapi == DATADOG_PHP_SAPI_FRANKENPHP) {
        return;
    }

    bool install_crashtracker = get_DD_INSTRUMENTATION_TELEMETRY_ENABLED() && get_DD_CRASHTRACKING_ENABLED();

    bool install_backtrace_handler = get_DD_TRACE_HEALTH_METRICS_ENABLED();
#if DDTRACE_HAVE_BACKTRACE
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

struct {
    int sig;
    siginfo_t si;
    void *uc;
} dd_signal_data;

static int dd_call_prev_handler(bool flush) {
    struct sigaction prev_sigaction = dd_signal_data.sig == SIGINT ? dd_sigint_prev_sigaction : dd_sigterm_prev_sigaction;
    void *prev_handler = (prev_sigaction.sa_flags & SA_SIGINFO) ? (void *)prev_sigaction.sa_sigaction : (void *)prev_sigaction.sa_handler;
    if (prev_handler == SIG_IGN) {
        return 0;
    }

    if (flush) {
        ddog_sidecar_flush_traces(&ddtrace_sidecar);
    }

    if (prev_handler == SIG_DFL) {
        _exit(0);
    }

    if (prev_sigaction.sa_flags & SA_SIGINFO) {
        (*prev_sigaction.sa_sigaction)(dd_signal_data.sig, &dd_signal_data.si, dd_signal_data.uc);
    } else {
        (*prev_sigaction.sa_handler)(dd_signal_data.sig);
    }

    return 0;

}

static int dd_sigterm_cleanup_thread(void *arg) {
    return dd_call_prev_handler(true);
}

static void dd_sigint_sigterm_handler(int sig, siginfo_t *si, void *uc) {
    dd_signal_data.sig = sig;
    memcpy(&dd_signal_data.si, si, sizeof(*si));
    dd_signal_data.uc = uc;

    if (ddtrace_sidecar) {
        // Spawn a thread using clone() to perform sidecar cleanup asynchronously to avoid async unsafeness in the signal handler
        void *stack_top = dd_signal_async_stack + dd_signal_async_stack_size;
        int flags = CLONE_VM | CLONE_FS | CLONE_FILES | CLONE_SIGHAND | CLONE_THREAD;
        clone(dd_sigterm_cleanup_thread, stack_top, flags, NULL);
    } else {
        dd_call_prev_handler(false);
    }
}
#endif

void ddtrace_signals_minit(void) {
#if __linux
    dd_sigint_sigterm_sigaction.sa_sigaction = dd_sigint_sigterm_handler;
    dd_sigint_sigterm_sigaction.sa_flags = SA_SIGINFO;
    sigemptyset(&dd_sigint_sigterm_sigaction.sa_mask);
    if (get_global_DD_TRACE_FORCE_FLUSH_ON_SIGTERM()) {
        dd_signals_init_async_stack();
        sigaction(SIGTERM, &dd_sigint_sigterm_sigaction, &dd_sigterm_prev_sigaction);
    }
    if (get_global_DD_TRACE_FORCE_FLUSH_ON_SIGINT()) {
        dd_signals_init_async_stack();
        sigaction(SIGINT, &dd_sigint_sigterm_sigaction, &dd_sigint_prev_sigaction);
    }
#endif
}

void ddtrace_signals_mshutdown(void) {
#if __linux
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
void ddtrace_signals_first_rinit(void) {}
void ddtrace_signals_mshutdown(void) {}
#endif

// This allows us to include the executing php binary and extensions themselves in the core dump too
void ddtrace_set_coredumpfilter(void) {
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
