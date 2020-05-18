#include "auto_flush.h"

#include <php.h>

#include "configuration.h"
#include "engine_api.h"
#include "engine_hooks.h"  // for ddtrace_backup_error_handling

ZEND_RESULT_CODE ddtrace_flush_tracer() {
    zval tracer, retval;
    zend_class_entry *GlobalTracer_ce = ddtrace_lookup_ce(ZEND_STRL("DDTrace\\GlobalTracer"));
    bool success = true;

    zend_object *exception = NULL, *prev_exception = NULL;
    if (EG(exception)) {
        exception = EG(exception);
        EG(exception) = NULL;
        prev_exception = EG(prev_exception);
        EG(prev_exception) = NULL;
        zend_clear_exception();
    }

    ddtrace_error_handling eh;
    ddtrace_backup_error_handling(&eh, EH_THROW);

    zend_bool orig_disable_in_current_request = DDTRACE_G(disable_in_current_request);
    DDTRACE_G(disable_in_current_request) = 1;

    // $tracer = \DDTrace\GlobalTracer::get();
    if (!GlobalTracer_ce ||
        ddtrace_call_method(NULL, GlobalTracer_ce, NULL, ZEND_STRL("get"), &tracer, 0, NULL) == FAILURE) {
        DDTRACE_G(disable_in_current_request) = orig_disable_in_current_request;

        ddtrace_restore_error_handling(&eh);
        ddtrace_maybe_clear_exception();
        if (exception) {
            EG(exception) = exception;
            EG(prev_exception) = prev_exception;
            zend_throw_exception_internal(NULL);
        }
        return FAILURE;
    }

    if (Z_TYPE(tracer) == IS_OBJECT) {
        zend_object *obj = Z_OBJ(tracer);
        zend_class_entry *ce = obj->ce;
        success = ddtrace_call_method(obj, ce, NULL, ZEND_STRL("flush"), &retval, 0, NULL) == SUCCESS &&
                  ddtrace_call_method(obj, ce, NULL, ZEND_STRL("reset"), &retval, 0, NULL) == SUCCESS;
    }

    DDTRACE_G(disable_in_current_request) = orig_disable_in_current_request;

    ddtrace_restore_error_handling(&eh);
    ddtrace_maybe_clear_exception();
    if (exception) {
        EG(exception) = exception;
        EG(prev_exception) = prev_exception;
        zend_throw_exception_internal(NULL);
    }

    zval_dtor(&tracer);
    zval_dtor(&retval);

    return success ? SUCCESS : FAILURE;
}
