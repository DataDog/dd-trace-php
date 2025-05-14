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

static bool ddtrace_crashtracker_check_result(ddog_VoidResult result, const char *msg) {
    if (result.tag != DDOG_VOID_RESULT_OK) {
        ddog_CharSlice error_msg = ddog_Error_message(&result.err);
        LOG(ERROR, "%s : %.*s", msg, (int) error_msg.len, error_msg.ptr);
        ddog_Error_drop(&result.err);
        return false;
    }

    return true;
}

#if PHP_VERSION_ID >= 80000
static zend_never_inline ZEND_COLD void ddtrace_crasht_failed_tag_push(
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

 // This doesn't increase the refcount of the returned string (no cleanup).
static zend_always_inline zend_string *ddtrace_crasht_ini_get_value(ddog_CharSlice ini) {
    zend_string *ini_zstr = zend_string_init(ini.ptr, ini.len, true);
    zend_string *value = zend_ini_get_value(ini_zstr);
    zend_string_release(ini_zstr);
    return value;
}

// Pass in a key like "php.opcache.enable" and "php." will get stripped off,
// and that's what the INI setting will be.
static void ddtrace_crasht_add_opcache_tag(
    ddog_Vec_Tag *tags,
    ddog_CharSlice key
) {
    const ddog_CharSlice PREFIX = DDOG_CHARSLICE_C("php.");
    ZEND_ASSERT(key.len > PREFIX.len && memcmp(key.ptr, PREFIX.ptr, PREFIX.len) == 0);

    ddog_CharSlice ini = {
        .ptr = key.ptr + PREFIX.len,
        .len = key.len - PREFIX.len,
    };
    zend_string *value = ddtrace_crasht_ini_get_value(ini);
    // On PHP 8.0+ these INI should all exist, but guard against the NULL case
    // in case something goes wrong, or this changes in a future version.
    ddog_CharSlice val = DDOG_CHARSLICE_C("[not found]");
    if (value) {
        val.ptr = ZSTR_VAL(value);
        val.len = ZSTR_LEN(value);
    }
    ddog_Vec_Tag_PushResult result = ddog_Vec_Tag_push(tags, key, val);
    if (UNEXPECTED(result.tag != DDOG_VEC_TAG_PUSH_RESULT_OK)) {
        ddtrace_crasht_failed_tag_push(&result.err, key);
    }
}

static zend_extension *ddtrace_crasht_find_zend_extension(const char *name) {
    //if (maybe_opcache->name && strcmp(maybe_opcache->name, "Zend OPcache") == 0) {
    const zend_llist *list = &zend_extensions;
    zend_extension *maybe_opcache = NULL;
    for (const zend_llist_element *item = list->head; item; item = item->next) {
        maybe_opcache = (zend_extension *)item->data;
        if (maybe_opcache->name && strcmp(maybe_opcache->name, name) == 0) {
            return maybe_opcache;
        }
    }
    return NULL;
}
#endif

// Fetches certain opcache tags and adds them with the pattern of php.opcache.*.
static void ddtrace_crasht_add_opcache_tags(ddog_Vec_Tag *tags) {
#if PHP_VERSION_ID >= 80000
    // If opcache isn't loaded, don't attach any opcache tags.
    if (!ddtrace_crasht_find_zend_extension("Zend OPcache")) {
        return;
    }

    // opcache.jit_buffer_size is INI_SYSTEM, so we can check it now. If it's
    // zero, then JIT won't operate.
    ddog_CharSlice jit_buffer_size = DDOG_CHARSLICE_C("[not found]");
    {
        ddog_CharSlice ini = DDOG_CHARSLICE_C("opcache.jit_buffer_size");
        zend_string *value = ddtrace_crasht_ini_get_value(ini);
        if (!value) {
            return;
        }

        // Parse the quantity similarly to OnUpdateLong.
#if PHP_VERSION_ID >= 80200
        zend_string *errstr = NULL;
        zend_long quantity = zend_ini_parse_quantity(value, &errstr);
        if (errstr) zend_string_release(errstr);
#else
        zend_long quantity = zend_atol(ZSTR_VAL(value), ZSTR_LEN(value));
#endif
        if (quantity <= 0) {
            return;
        }
        jit_buffer_size.ptr = ZSTR_VAL(value);
        jit_buffer_size.len = ZSTR_LEN(value);
    }

    // The CLI SAPI has an additional configuration for being enabled. This is
    // INI_SYSTEM so we can check it here.
    bool is_cli_sapi = strcmp("cli", sapi_module.name) == 0;
    ddog_CharSlice enable_cli = DDOG_CHARSLICE_C("[not found]");
    if (is_cli_sapi) {
        ddog_CharSlice ini = DDOG_CHARSLICE_C("opcache.enable_cli");
        zend_string *value = ddtrace_crasht_ini_get_value(ini);
        if (!value || !zend_ini_parse_bool(value)) {
            return;
        }
        enable_cli.ptr = ZSTR_VAL(value);
        enable_cli.len = ZSTR_LEN(value);
    }


    // The others are INI_ALL, so it's possible that they change at runtime.
    ddtrace_crasht_add_opcache_tag(tags, DDOG_CHARSLICE_C("php.opcache.enable"));
    if (is_cli_sapi) {
        ddog_CharSlice key = DDOG_CHARSLICE_C("php.opcache.enable_cli");
        ddog_Vec_Tag_PushResult result = ddog_Vec_Tag_push(tags, key, enable_cli);
        if (UNEXPECTED(result.tag != DDOG_VEC_TAG_PUSH_RESULT_OK)) {
            ddtrace_crasht_failed_tag_push(&result.err, key);
        }
    }

    ddtrace_crasht_add_opcache_tag(tags, DDOG_CHARSLICE_C("php.opcache.jit"));

    ddog_CharSlice key = DDOG_CHARSLICE_C("php.opcache.jit_buffer_size");
    ddog_Vec_Tag_PushResult result = ddog_Vec_Tag_push(tags, key, jit_buffer_size);
    if (UNEXPECTED(result.tag != DDOG_VEC_TAG_PUSH_RESULT_OK)) {
        ddtrace_crasht_failed_tag_push(&result.err, key);
    }
#endif
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
    ddtrace_crasht_add_opcache_tags(&tags);

    const ddog_crasht_Metadata metadata = ddtrace_setup_crashtracking_metadata(&tags);

    ddtrace_crashtracker_check_result(
        ddog_crasht_init_without_receiver(
            config,
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

    bool install_crashtracker = get_DD_INSTRUMENTATION_TELEMETRY_ENABLED() && get_DD_CRASHTRACKING_ENABLED();

    bool install_backtrace_handler = get_DD_TRACE_HEALTH_METRICS_ENABLED();
#if DDTRACE_HAVE_BACKTRACE
    install_backtrace_handler |= get_DD_LOG_BACKTRACE();
#endif

    if (install_crashtracker) {
        ddtrace_init_crashtracker();
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
