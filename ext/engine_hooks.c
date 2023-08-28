#include "engine_hooks.h"

#include <php.h>
#include <time.h>

#include "ddtrace.h"
#include <components/log/log.h>
#include "zend_hrtime.h"
#include "span.h"
#include "zend_extensions.h"
#include <zai_string/string.h>

#ifdef PHP_WIN32
#include "win32/param.h"
#include "win32/winutil.h"
#define GET_DL_ERROR()	php_win_err()
#else
#include <sys/param.h>
#define GET_DL_ERROR()	DL_ERROR()
#endif

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static zend_op_array *(*_prev_compile_file)(zend_file_handle *file_handle, int type);

static void _compile_minit(void);
static void _compile_mshutdown(void);

void (*ddtrace_prev_error_cb)(DDTRACE_ERROR_CB_PARAMETERS);

typedef void (*profiling_notify_trace_finished_t)(uint64_t local_root_span_id,
                                        zai_str span_type,
                                        zai_str resource);
profiling_notify_trace_finished_t profiling_notify_trace_finished = NULL;

void (*profiling_interrupt_function)(zend_execute_data *) = NULL;

// Check if the profiler is loaded, and if, so it will locate certain symbols so cross-product features can be enabled.
void dd_search_for_profiling_symbols(void *arg) {
    zend_extension *extension = (zend_extension *)arg;
    if (extension->name && strcmp(extension->name, "datadog-profiling") == 0) {
        DL_HANDLE handle = extension->handle;

        profiling_interrupt_function = (void(*)(zend_execute_data *))DL_FETCH_SYMBOL(handle, "datadog_profiling_interrupt_function");
        if (UNEXPECTED(!profiling_interrupt_function)) {
            LOG(Warn, "[Datadog Trace] Profiling was detected, but locating symbol %s failed: %s\n", "datadog_profiling_interrupt_function",
                               GET_DL_ERROR());
        }

        profiling_notify_trace_finished = (profiling_notify_trace_finished_t)DL_FETCH_SYMBOL(handle, "datadog_profiling_notify_trace_finished");
        if (UNEXPECTED(!profiling_notify_trace_finished)) {
            LOG(Warn, "[Datadog Trace] Profiling v%s was detected, but locating symbol failed: %s\n", extension->version, GET_DL_ERROR());
        }
    }
}

void ddtrace_fetch_profiling_symbols(void) {
    zend_llist_apply(&zend_extensions, dd_search_for_profiling_symbols);
}

void ddtrace_engine_hooks_minit(void) {
    _compile_minit();

    ddtrace_prev_error_cb = zend_error_cb;
    zend_error_cb = ddtrace_error_cb;
}

void ddtrace_engine_hooks_mshutdown(void) {
    if (ddtrace_prev_error_cb == ddtrace_error_cb) {
        zend_error_cb = ddtrace_prev_error_cb;
    }

    _compile_mshutdown();
}

static zend_op_array *_dd_compile_file(zend_file_handle *file_handle, int type) {
    zend_op_array *res;
    uint64_t start = zend_hrtime();
    res = _prev_compile_file(file_handle, type);
    DDTRACE_G(compile_time_microseconds) += (int64_t)(zend_hrtime() - start);
    return res;
}

static void _compile_minit(void) {
    _prev_compile_file = zend_compile_file;
    zend_compile_file = _dd_compile_file;
}

static void _compile_mshutdown(void) {
    if (zend_compile_file == _dd_compile_file) {
        zend_compile_file = _prev_compile_file;
    }
}

void ddtrace_compile_time_reset(void) { DDTRACE_G(compile_time_microseconds) = 0; }

int64_t ddtrace_compile_time_get(void) { return DDTRACE_G(compile_time_microseconds); }

extern inline void ddtrace_backup_error_handling(ddtrace_error_handling *eh, zend_error_handling_t mode);

void ddtrace_restore_error_handling(ddtrace_error_handling *eh) {
    if (PG(last_error_message)) {
        if (PG(last_error_message) != eh->message) {
#if PHP_VERSION_ID < 80000
            free(PG(last_error_message));
#else
            zend_string_release(PG(last_error_message));
#endif
        }
        if (PG(last_error_file) != eh->file) {
#if PHP_VERSION_ID < 80100
            free(PG(last_error_file));
#else
            zend_string_release(PG(last_error_file));
#endif
        }
    }
    zend_restore_error_handling(&eh->error_handling);
    PG(last_error_type) = eh->type;
    PG(last_error_message) = eh->message;
    PG(last_error_file) = eh->file;
    PG(last_error_lineno) = eh->lineno;
    EG(error_reporting) = eh->error_reporting;
}

extern inline void ddtrace_sandbox_end(ddtrace_sandbox_backup *backup);
extern inline ddtrace_sandbox_backup ddtrace_sandbox_begin(void);
extern inline void ddtrace_maybe_clear_exception(void);
