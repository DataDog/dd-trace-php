#include "auto_flush.h"

#include <methods/methods.h>

#include "ddtrace.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static bool dd_call_method_ignore_retval(zval *obj, const char *method, size_t method_len TSRMLS_DC) {
    zval *retval = NULL;
    bool result = zai_call_method_without_args(obj, method, method_len, &retval);
    if (retval) {
        zval_ptr_dtor(&retval);
        retval = NULL;
    }
    return result;
}

bool ddtrace_flush_tracer(TSRMLS_D) {
    zend_class_entry *ce = zai_class_lookup(ZEND_STRL("ddtrace\\globaltracer"));
    if (!ce) return false;

    zval *tracer = NULL;
    // $tracer = \DDTrace\GlobalTracer::get();
    bool result = zai_call_static_method_without_args(ce, ZEND_STRL("get"), &tracer);
    if (!result) return false;

    if (tracer && Z_TYPE_P(tracer) == IS_OBJECT) {
        zend_bool orig_disable = DDTRACE_G(disable_in_current_request);
        DDTRACE_G(disable_in_current_request) = 1;

        // $tracer->flush();
        // $tracer->reset();
        result = dd_call_method_ignore_retval(tracer, ZEND_STRL("flush") TSRMLS_CC) &&
                 dd_call_method_ignore_retval(tracer, ZEND_STRL("reset") TSRMLS_CC);

        DDTRACE_G(disable_in_current_request) = orig_disable;
    }

    if (tracer) {
        zval_ptr_dtor(&tracer);
        tracer = NULL;
    }

    return result;
}
