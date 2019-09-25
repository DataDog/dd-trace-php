#include "trace.h"

#include <Zend/zend_exceptions.h>
#include <php.h>

#include "ddtrace.h"
#include "dispatch.h"
#include "dispatch_compat.h"
#include "logging.h"
#include "memory_limit.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

/* Move these to a header if dispatch.c still needs it */
#if PHP_VERSION_ID >= 70100
#define RETURN_VALUE_USED(opline) ((opline)->result_type != IS_UNUSED)
#else
#define RETURN_VALUE_USED(opline) (!((opline)->result_type & EXT_TYPE_UNUSED))
#endif

void ddtrace_trace_dispatch(ddtrace_dispatch_t *dispatch, zend_function *fbc,
                            zend_execute_data *execute_data TSRMLS_DC) {
    int fcall_status;
    const zend_op *opline = EX(opline);

    zval *user_retval = NULL, *user_args;
#if PHP_VERSION_ID < 70000
    ALLOC_INIT_ZVAL(user_retval);
    ALLOC_INIT_ZVAL(user_args);
    zval *exception = NULL, *prev_exception = NULL;
#else
    zend_object *exception = NULL, *prev_exception = NULL;
    zval rv, user_args_zv;
    INIT_ZVAL(user_args_zv);
    INIT_ZVAL(rv);
    user_args = &user_args_zv;
    user_retval = (RETURN_VALUE_USED(opline) ? EX_VAR(opline->result.var) : &rv);
#endif

    ddtrace_span_t *span = ddtrace_open_span(TSRMLS_C);
#if PHP_VERSION_ID < 70000
    fcall_status = ddtrace_forward_call(execute_data, fbc, user_retval TSRMLS_CC);
#else
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};
    fcall_status = ddtrace_forward_call(EX(call), fbc, user_retval, &fci, &fcc TSRMLS_CC);
#endif
    dd_trace_stop_span_time(span);

    ddtrace_copy_function_args(execute_data, user_args);
    if (EG(exception)) {
        exception = EG(exception);
        EG(exception) = NULL;
        prev_exception = EG(prev_exception);
        EG(prev_exception) = NULL;
        ddtrace_span_attach_exception(span, exception);
        zend_clear_exception(TSRMLS_C);
    }

    BOOL_T keep_span = TRUE;
    if (fcall_status == SUCCESS && Z_TYPE(dispatch->callable) == IS_OBJECT) {
        zend_error_handling error_handling;
        int orig_error_reporting = EG(error_reporting);
        EG(error_reporting) = 0;
#if PHP_VERSION_ID < 70000
        zend_replace_error_handling(EH_SUPPRESS, NULL, &error_handling TSRMLS_CC);
#else
        zend_replace_error_handling(EH_THROW, NULL, &error_handling TSRMLS_CC);
#endif
        keep_span = ddtrace_execute_tracing_closure(&dispatch->callable, span->span_data, execute_data, user_args,
                                                    user_retval, exception TSRMLS_CC);
        zend_restore_error_handling(&error_handling TSRMLS_CC);
        EG(error_reporting) = orig_error_reporting;
        // If the tracing closure threw an exception, ignore it to not impact the original call
        if (EG(exception)) {
            ddtrace_log_debug("Exeception thrown in the tracing closure");
            if (!DDTRACE_G(strict_mode)) {
                zend_clear_exception(TSRMLS_C);
            }
        }
    }

#if PHP_VERSION_ID < 70000
    zval_ptr_dtor(&user_args);
#else
    zval_ptr_dtor(user_args);
#endif

    if (keep_span == TRUE) {
        ddtrace_close_span(TSRMLS_C);
    } else {
        ddtrace_drop_span(TSRMLS_C);
    }

    if (exception) {
        EG(exception) = exception;
        EG(prev_exception) = prev_exception;
#if PHP_VERSION_ID < 70000
        EG(opline_before_exception) = (zend_op *)opline;
        EG(current_execute_data)->opline = EG(exception_op);
#else
        zend_throw_exception_internal(NULL TSRMLS_CC);
#endif
    }

#if PHP_VERSION_ID < 50500
    (void)opline;  // TODO Make work on PHP 5.4
#elif PHP_VERSION_ID < 70000
    // Put the original return value on the opline
    if (RETURN_VALUE_USED(opline)) {
        EX_TMP_VAR(execute_data, opline->result.var)->var.ptr = user_retval;
    } else {
        zval_ptr_dtor(&user_retval);
    }
#else
    zend_fcall_info_args_clear(&fci, 0);
    if (!RETURN_VALUE_USED(opline)) {
        zval_dtor(&rv);
    }
#endif

#if PHP_VERSION_ID < 50500
    // Free any remaining args
    zend_vm_stack_clear_multiple(TSRMLS_C);
#elif PHP_VERSION_ID < 70000
    // Since zend_leave_helper isn't run we have to dtor $this here
    // https://lxr.room11.org/xref/php-src%405.6/Zend/zend_vm_def.h#1905
    if (EX(call)->object) {
        zval_ptr_dtor(&EX(call)->object);
    }
    // Free any remaining args
    zend_vm_stack_clear_multiple(0 TSRMLS_CC);
    // Restore call for internal functions
    EX(call)--;
#else
    // Since zend_leave_helper isn't run we have to dtor $this here
    // https://lxr.room11.org/xref/php-src%407.4/Zend/zend_vm_def.h#2888
    if (ZEND_CALL_INFO(EX(call)) & ZEND_CALL_RELEASE_THIS) {
        OBJ_RELEASE(Z_OBJ(EX(call)->This));
    }
    // Restore call for internal functions
    EX(call) = EX(call)->prev_execute_data;
#endif
}

BOOL_T ddtrace_tracer_is_limited(TSRMLS_D) {
    int64_t limit = get_dd_trace_spans_limit();
    if (limit >= 0 && (int64_t)(DDTRACE_G(open_spans_count) + DDTRACE_G(closed_spans_count)) >= limit) {
        return TRUE;
    }
    return ddtrace_check_memory_under_limit(TSRMLS_C) == TRUE ? FALSE : TRUE;
}
