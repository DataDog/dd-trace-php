#include "engine_hooks.h"

#include <php.h>
#include <stdint.h>
#include <time.h>

#include "ddtrace.h"
#include "dispatch.h"
#if PHP_VERSION_ID < 70000
#include "dispatch_compat_php5.h"
#endif

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

// True gloals that need not worry about thread safety
#if PHP_VERSION_ID >= 70000
static user_opcode_handler_t _prev_icall_handler;
static user_opcode_handler_t _prev_ucall_handler;
#endif
static user_opcode_handler_t _prev_fcall_handler;
static user_opcode_handler_t _prev_fcall_by_name_handler;

static zend_op_array *(*_prev_compile_file)(zend_file_handle *file_handle, int type TSRMLS_DC);

static void _opcode_minit(void);
static void _opcode_mshutdown(void);
static void _compile_minit(void);
static void _compile_mshutdown(void);

void ddtrace_engine_hooks_minit(void) {
    _opcode_minit();
    _compile_minit();
}
void ddtrace_engine_hooks_mshutdown(void) {
    _compile_mshutdown();
    _opcode_mshutdown();
}

static void _opcode_minit(void) {
#if PHP_VERSION_ID >= 70000
    _prev_icall_handler = zend_get_user_opcode_handler(ZEND_DO_ICALL);
    _prev_ucall_handler = zend_get_user_opcode_handler(ZEND_DO_UCALL);
    zend_set_user_opcode_handler(ZEND_DO_ICALL, ddtrace_wrap_fcall);
    zend_set_user_opcode_handler(ZEND_DO_UCALL, ddtrace_wrap_fcall);
#endif

    _prev_fcall_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL);
    _prev_fcall_by_name_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL_BY_NAME);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, ddtrace_wrap_fcall);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, ddtrace_wrap_fcall);
}

static void _opcode_mshutdown(void) {
#if PHP_VERSION_ID >= 70000
    zend_set_user_opcode_handler(ZEND_DO_ICALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_UCALL, NULL);
#endif
    zend_set_user_opcode_handler(ZEND_DO_FCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, NULL);
}

int ddtrace_opcode_default_dispatch(zend_execute_data *execute_data TSRMLS_DC) {
    if (!EX(opline)->opcode) {
        return ZEND_USER_OPCODE_DISPATCH;
    }
    switch (EX(opline)->opcode) {
#if PHP_VERSION_ID >= 70000
        case ZEND_DO_ICALL:
            if (_prev_icall_handler) {
                return _prev_icall_handler(execute_data TSRMLS_CC);
            }
            break;

        case ZEND_DO_UCALL:
            if (_prev_ucall_handler) {
                return _prev_ucall_handler(execute_data TSRMLS_CC);
            }
            break;
#endif
        case ZEND_DO_FCALL:
            if (_prev_fcall_handler) {
                return _prev_fcall_handler(execute_data TSRMLS_CC);
            }
            break;

        case ZEND_DO_FCALL_BY_NAME:
            if (_prev_fcall_by_name_handler) {
                return _prev_fcall_by_name_handler(execute_data TSRMLS_CC);
            }
            break;
    }
    return ZEND_USER_OPCODE_DISPATCH;
}

static uint64_t _get_microseconds() {
    struct timespec time;
    if (clock_gettime(CLOCK_MONOTONIC, &time) == 0) {
        return time.tv_sec * 1000000U + time.tv_nsec / 1000U;
    }
    return 0U;
}

static zend_op_array *_dd_compile_file(zend_file_handle *file_handle, int type TSRMLS_DC) {
    zend_op_array *res;
    uint64_t start = _get_microseconds();
    res = _prev_compile_file(file_handle, type TSRMLS_CC);
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

void ddtrace_compile_time_reset(TSRMLS_D) { DDTRACE_G(compile_time_microseconds) = 0; }

int64_t ddtrace_compile_time_get(TSRMLS_D) { return DDTRACE_G(compile_time_microseconds); }
