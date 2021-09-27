#include "engine_hooks.h"

#include <Zend/zend_observer.h>
#include <php.h>
#include <time.h>

#include "configuration.h"
#include "ddtrace.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static zend_op_array *(*_prev_compile_file)(zend_file_handle *file_handle, int type);

static void _compile_minit(void);
static void _compile_mshutdown(void);

void (*ddtrace_prev_error_cb)(DDTRACE_ERROR_CB_PARAMETERS);

void ddtrace_engine_hooks_minit(void) {
    _compile_minit();

    zend_observer_fcall_register(ddtrace_observer_fcall_init);

    ddtrace_prev_error_cb = zend_error_cb;
    zend_error_cb = ddtrace_error_cb;
}

void ddtrace_engine_hooks_mshutdown(void) { _compile_mshutdown(); }

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

void ddtrace_restore_error_handling(ddtrace_error_handling *eh) {
    if (PG(last_error_message)) {
        if (PG(last_error_message) != eh->message) {
            zend_string_release(PG(last_error_message));
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
