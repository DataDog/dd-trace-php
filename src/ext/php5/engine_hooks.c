#include "engine_hooks.h"

#include "dispatch.h"
#include "dispatch_compat_php5.h"

// True gloals; only modify in minit/mshutdown
static user_opcode_handler_t _prev_fcall_handler;
static user_opcode_handler_t _prev_fcall_by_name_handler;

void ddtrace_opcode_minit(void) {
    _prev_fcall_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL);
    _prev_fcall_by_name_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL_BY_NAME);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, ddtrace_wrap_fcall);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, ddtrace_wrap_fcall);
}

void ddtrace_opcode_mshutdown(void) {
    zend_set_user_opcode_handler(ZEND_DO_FCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, NULL);
}

int ddtrace_opcode_default_dispatch(zend_execute_data *execute_data TSRMLS_DC) {
    if (!EX(opline)->opcode) {
        return ZEND_USER_OPCODE_DISPATCH;
    }
    switch (EX(opline)->opcode) {
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
