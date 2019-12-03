#include "engine_hooks.h"

#if PHP_VERSION_ID < 70000
#include "php5/engine_hooks.c"
#else
#include "php7/engine_hooks.c"
#endif

#include <php.h>
#include <time.h>

#include "ddtrace.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static zend_op_array *(*_prev_compile_file)(zend_file_handle *file_handle, int type TSRMLS_DC);

static void _compile_minit(void);
static void _compile_mshutdown(void);

void ddtrace_engine_hooks_minit(void) {
    ddtrace_opcode_minit();
    _compile_minit();
}
void ddtrace_engine_hooks_mshutdown(void) {
    _compile_mshutdown();
    ddtrace_opcode_mshutdown();
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
