#include "engine_hooks.h"

#include "dispatch.h"

// True gloals; only modify in minit/mshutdown
static user_opcode_handler_t _prev_icall_handler;
static user_opcode_handler_t _prev_ucall_handler;
static user_opcode_handler_t _prev_fcall_handler;
static user_opcode_handler_t _prev_fcall_by_name_handler;

void ddtrace_opcode_minit(void) {
    _prev_icall_handler = zend_get_user_opcode_handler(ZEND_DO_ICALL);
    _prev_ucall_handler = zend_get_user_opcode_handler(ZEND_DO_UCALL);
    _prev_fcall_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL);
    _prev_fcall_by_name_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL_BY_NAME);
    zend_set_user_opcode_handler(ZEND_DO_ICALL, ddtrace_wrap_fcall);
    zend_set_user_opcode_handler(ZEND_DO_UCALL, ddtrace_wrap_fcall);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, ddtrace_wrap_fcall);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, ddtrace_wrap_fcall);
}

void ddtrace_opcode_mshutdown(void) {
    zend_set_user_opcode_handler(ZEND_DO_ICALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_UCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, NULL);
}

int ddtrace_opcode_default_dispatch(zend_execute_data *execute_data) {
    if (!EX(opline)->opcode) {
        return ZEND_USER_OPCODE_DISPATCH;
    }
    switch (EX(opline)->opcode) {
        case ZEND_DO_ICALL:
            if (_prev_icall_handler) {
                return _prev_icall_handler(execute_data);
            }
            break;

        case ZEND_DO_UCALL:
            if (_prev_ucall_handler) {
                return _prev_ucall_handler(execute_data);
            }
            break;
        case ZEND_DO_FCALL:
            if (_prev_fcall_handler) {
                return _prev_fcall_handler(execute_data);
            }
            break;

        case ZEND_DO_FCALL_BY_NAME:
            if (_prev_fcall_by_name_handler) {
                return _prev_fcall_by_name_handler(execute_data);
            }
            break;
    }
    return ZEND_USER_OPCODE_DISPATCH;
}
