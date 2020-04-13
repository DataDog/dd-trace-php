#include "auto_flush.h"

#include <php.h>

#include "configuration.h"
#include "engine_api.h"
#include "engine_hooks.h"  // for ddtrace_backup_error_handling

bool ddtrace_flush_tracer() {
    zval tracer, retval;
    zend_function *Configuration_get_fe = NULL;
    zend_class_entry *GlobalTracer_ce = ddtrace_lookup_ce(ZEND_STRL("DDTrace\\GlobalTracer"));
    bool success = true;

    ddtrace_error_handling eh;
    ddtrace_backup_error_handling(&eh, EH_THROW);

    // $tracer = \DDTrace\GlobalTracer::get();
    if (!GlobalTracer_ce || ddtrace_call_method(NULL, GlobalTracer_ce, &Configuration_get_fe, ZEND_STRL("get"), &tracer,
                                                0, NULL) == FAILURE) {
        ddtrace_restore_error_handling(&eh);
        ddtrace_maybe_clear_exception();
        return false;
    }

    if (Z_TYPE(tracer) == IS_OBJECT) {
        // $tracer->flush();
        if (ddtrace_call_method(Z_OBJ(tracer), Z_OBJ(tracer)->ce, NULL, ZEND_STRL("flush"), &retval, 0, NULL) ==
            FAILURE) {
            success = false;
        }

        // $tracer->reset();
        if (success && ddtrace_call_method(Z_OBJ(tracer), Z_OBJ(tracer)->ce, NULL, ZEND_STRL("reset"), &retval, 0,
                                           NULL) == FAILURE) {
            success = false;
        }
    }

    ddtrace_restore_error_handling(&eh);
    ddtrace_maybe_clear_exception();

    zval_dtor(&tracer);
    zval_dtor(&retval);

    return success;
}
