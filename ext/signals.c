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

#include <components-rs/common.h>
#include <components-rs/ddtrace.h>
#include <components-rs/crashtracker.h>

#if defined HAVE_EXECINFO_H && defined backtrace_size_t && defined HAVE_BACKTRACE
#define DDTRACE_HAVE_BACKTRACE 1
#else
#define DDTRACE_HAVE_BACKTRACE 0
#endif

#if DDTRACE_HAVE_BACKTRACE
#include <execinfo.h>
#endif

// true globals; only modify in MINIT/MSHUTDOWN
static stack_t ddtrace_altstack;
static struct sigaction ddtrace_sigaction;
static char crashtracker_socket_path[100] = {0};

#define MAX_STACK_SIZE 1024
#define MIN_STACKSZ 16384  // enough to hold void *array[MAX_STACK_SIZE] plus a couple kilobytes

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static void ddtrace_sigsegv_handler(int sig) {
    if (!DDTRACE_G(backtrace_handler_already_run)) {
        DDTRACE_G(backtrace_handler_already_run) = true;
        LOG(ERROR, "Segmentation fault");

#if HAVE_SIGACTION
        bool health_metrics_enabled = get_DD_TRACE_HEALTH_METRICS_ENABLED();
        if (health_metrics_enabled) {
            dogstatsd_client *client = &DDTRACE_G(dogstatsd_client);
            const char *metric = "datadog.tracer.uncaught_exceptions";
            const char *tags = "class:sigsegv";
            dogstatsd_client_status status = dogstatsd_client_count(client, metric, "1", tags);

            if (status == DOGSTATSD_CLIENT_OK) {
                LOG(ERROR, "sigsegv health metric sent");
            }
        }
#endif

#if DDTRACE_HAVE_BACKTRACE
        LOG(ERROR, "Datadog PHP Trace extension (DEBUG MODE)");
        LOG(ERROR, "Received Signal %d", sig);
        void *array[MAX_STACK_SIZE];
        backtrace_size_t size = backtrace(array, MAX_STACK_SIZE);
        if (size == MAX_STACK_SIZE) {
            LOG(ERROR, "Note: max stacktrace size reached");
        }

        LOG(ERROR, "Note: Backtrace below might be incomplete and have wrong entries due to optimized runtime");
        LOG(ERROR, "Backtrace:");

        char **backtraces = backtrace_symbols(array, size);
        if (backtraces) {
            for (backtrace_size_t i = 0; i < size; i++) {
                LOG(ERROR, backtraces[i]);
            }
            free(backtraces);
        }
#endif
    }

    // _Exit to avoid atexit() handlers, they may crash in this SIGSEGV signal handler...
    _Exit(128 + sig);
}

static bool ddtrace_crashtracker_check_result(ddog_crasht_Result result, const char *msg) {
    if (result.tag != DDOG_CRASHT_RESULT_OK) {
        ddog_CharSlice error_msg = ddog_Error_message(&result.err);
        LOG(ERROR, "%s : %.*s", msg, (int) error_msg.len, error_msg.ptr);
        ddog_Error_drop(&result.err);
        return false;
    }

    return true;
}

static void ddtrace_init_crashtracker() {
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

    ddog_crasht_Config config = {
        .endpoint = agent_endpoint,
        .timeout_secs = 5,
        .resolve_frames = DDOG_CRASHT_STACKTRACE_COLLECTION_ENABLED_WITH_INPROCESS_SYMBOLS,
        // Likely running in a container, so wait until the report is uploaded.
        // Otherwise, the container shutdown may stop the sidecar before it has finished uploading the crash report.
        .wait_for_receiver = getpid() == 1,
    };

    ddog_Vec_Tag tags = ddog_Vec_Tag_new();
    ddtrace_sidecar_push_tags(&tags, NULL);

    ddtrace_sidecar_push_tag(&tags, DDOG_CHARSLICE_C("is_crash"), DDOG_CHARSLICE_C("true"));
    ddtrace_sidecar_push_tag(&tags, DDOG_CHARSLICE_C("severity"), DDOG_CHARSLICE_C("crash"));
    ddtrace_sidecar_push_tag(&tags, DDOG_CHARSLICE_C("library_version"), DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION));
    ddtrace_sidecar_push_tag(&tags, DDOG_CHARSLICE_C("language"), DDOG_CHARSLICE_C("php"));
    ddtrace_sidecar_push_tag(&tags, DDOG_CHARSLICE_C("runtime"), DDOG_CHARSLICE_C("php"));

    uint8_t runtime_id[36];
    ddtrace_format_runtime_id(&runtime_id);
    ddtrace_sidecar_push_tag(&tags, DDOG_CHARSLICE_C("runtime-id"), (ddog_CharSlice) {.ptr = (char *) runtime_id, .len = sizeof(runtime_id)});

    const char *runtime_version = zend_get_module_version("Reflection");
    ddtrace_sidecar_push_tag(&tags, DDOG_CHARSLICE_C("runtime_version"), (ddog_CharSlice) {.ptr = (char *) runtime_version, .len = strlen(runtime_version)});

    const ddog_crasht_Metadata metadata = {
        .library_name = DDOG_CHARSLICE_C_BARE("dd-trace-php"),
        .library_version = DDOG_CHARSLICE_C_BARE(PHP_DDTRACE_VERSION),
        .family = DDOG_CHARSLICE_C("php"),
        .tags = &tags
    };

    ddtrace_crashtracker_check_result(
        ddog_crasht_init_with_unix_socket(
            config,
            socket_path,
            metadata
        ),
        "Cannot initialize CrashTracker"
    );

    ddog_endpoint_drop(agent_endpoint);
    ddog_Vec_Tag_drop(tags);
}

void ddtrace_signals_first_rinit(void) {
    DDTRACE_G(backtrace_handler_already_run) = false;

    // Signal handlers are causing issues with FrankenPHP.
    if (ddtrace_active_sapi == DATADOG_PHP_SAPI_FRANKENPHP) {
        return;
    }

    bool install_handler = get_DD_TRACE_HEALTH_METRICS_ENABLED();

#if DDTRACE_HAVE_BACKTRACE
    install_handler |= get_DD_LOG_BACKTRACE();
#endif

    /* Install a signal handler for SIGSEGV and run it on an alternate stack.
     * Using an alternate stack allows the handler to run even when the main
     * stack overflows.
     */
    if (install_handler) {
        size_t stack_size = SIGSTKSZ < MIN_STACKSZ ? MIN_STACKSZ : SIGSTKSZ;
        if ((ddtrace_altstack.ss_sp = malloc(stack_size))) {
            ddtrace_altstack.ss_size = stack_size;
            ddtrace_altstack.ss_flags = 0;
            if (sigaltstack(&ddtrace_altstack, NULL) == 0) {
                ddtrace_sigaction.sa_flags = SA_ONSTACK;
                ddtrace_sigaction.sa_handler = ddtrace_sigsegv_handler;
                sigemptyset(&ddtrace_sigaction.sa_mask);
                sigaction(SIGSEGV, &ddtrace_sigaction, NULL);
            }
        }
    }

    if (get_DD_INSTRUMENTATION_TELEMETRY_ENABLED() && get_DD_CRASHTRACKING_ENABLED()) {
        ddtrace_init_crashtracker();
    }
}

void ddtrace_signals_mshutdown(void) {
    free(ddtrace_altstack.ss_sp);
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
