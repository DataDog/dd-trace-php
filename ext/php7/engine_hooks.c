#include "engine_hooks.h"

#include <php.h>
#include <time.h>

#include "ddtrace.h"
#include "span.h"
#include "zend_extensions.h"
#include "logging.h"
#include "runtime.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static zend_op_array *(*_prev_compile_file)(zend_file_handle *file_handle, int type);

static void _compile_minit(void);
static void _compile_mshutdown(void);

void (*ddtrace_prev_error_cb)(DDTRACE_ERROR_CB_PARAMETERS);

static datadog_php_uuid dd_profiling_runtime_id_nil(void) {
    datadog_php_uuid uuid = DATADOG_PHP_UUID_INIT;
    return uuid;
}

datadog_php_uuid (*ddtrace_profiling_runtime_id)(void) = dd_profiling_runtime_id_nil;

void (*profiling_interrupt_function)(zend_execute_data *) = NULL;

// Check if the profiler is loaded, and if, so it will locate certain symbols so cross-product features can be enabled.
void dd_search_for_profiling_symbols(void *arg) {
    zend_extension *extension = (zend_extension *)arg;
    if (extension->name && strcmp(extension->name, "datadog-profiling") == 0) {
        DL_HANDLE handle = extension->handle;

        profiling_interrupt_function = DL_FETCH_SYMBOL(handle, "datadog_profiling_interrupt_function");
        if (UNEXPECTED(!profiling_interrupt_function)) {
            ddtrace_log_debugf("[Datadog Trace] Profiling was detected, but locating symbol %s failed: %s\n",
                               "datadog_profiling_interrupt_function", DL_ERROR());
        }

        datadog_php_uuid (*runtime_id)(void) = DL_FETCH_SYMBOL(handle, "datadog_profiling_runtime_id");
        if (EXPECTED(runtime_id)) {
            ddtrace_profiling_runtime_id = runtime_id;
        } else {
            ddtrace_log_debugf("[Datadog Trace] Profiling v%s was detected, but locating symbol failed: %s\n",
                               extension->version, DL_ERROR());
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

static uint64_t _get_microseconds() {
    struct timespec time;
    if (clock_gettime(CLOCK_MONOTONIC, &time) == 0) {
        return time.tv_sec * 1000000U + time.tv_nsec / 1000U;
    }
    return 0U;
}

static zend_op_array *_dd_compile_file(zend_file_handle *file_handle, int type) {
    zend_op_array *res;
    uint64_t start = _get_microseconds();
    res = _prev_compile_file(file_handle, type);
    DDTRACE_G(compile_time_microseconds) += (int64_t)(_get_microseconds() - start);
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
extern inline void ddtrace_restore_error_handling(ddtrace_error_handling *eh);
extern inline void ddtrace_sandbox_end(ddtrace_sandbox_backup *backup);
extern inline ddtrace_sandbox_backup ddtrace_sandbox_begin(void);
extern inline void ddtrace_maybe_clear_exception(void);
