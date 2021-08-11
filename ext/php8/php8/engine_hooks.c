#include "ext/php8/engine_hooks.h"

#include <Zend/zend_closures.h>
#include <Zend/zend_compile.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_generators.h>
#include <Zend/zend_interfaces.h>
#include <Zend/zend_observer.h>
#include <exceptions/exceptions.h>
#include <functions/functions.h>
#include <stdbool.h>

#include "ext/php8/compatibility.h"
#include "ext/php8/ddtrace.h"
#include "ext/php8/dispatch.h"
#include "ext/php8/engine_api.h"
#include "ext/php8/logging.h"
#include "ext/php8/span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

int ddtrace_resource = -1;
int ddtrace_op_array_extension = 0;

#define RETURN_VALUE_USED(opline) ((opline)->result_type != IS_UNUSED)

static zval *dd_call_this(zend_execute_data *call) {
    if (Z_TYPE(call->This) == IS_OBJECT && Z_OBJ(call->This) != NULL) {
        return &call->This;
    }
    return NULL;
}

/* Call dd_get_called_scope if you don't know if the call is a method or not;
 * if you already know it's a method then you can call zend_get_called_scope.
 */
static zend_class_entry *dd_get_called_scope(zend_execute_data *call) {
    return call->func->common.scope ? zend_get_called_scope(call) : NULL;
}

static void dd_try_fetch_executing_function_name(zend_execute_data *call, const char **scope, const char **colon,
                                                 const char **name) {
    *scope = "";
    *colon = "";
    *name = "(unknown function)";
    if (call && call->func && call->func->common.function_name) {
        zend_function *fbc = call->func;
        *name = ZSTR_VAL(fbc->common.function_name);
        if (fbc->common.scope) {
            *scope = ZSTR_VAL(fbc->common.scope->name);
            *colon = "::";
        }
    }
}

static ZEND_RESULT_CODE dd_sandbox_fci_call(zend_execute_data *call, zend_fcall_info *fci, zend_fcall_info_cache *fcc,
                                            int argc, ...) {
    ZEND_RESULT_CODE ret;
    va_list argv;

    va_start(argv, argc);
    zend_fcall_info_argv(fci, (uint32_t)argc, &argv);
    va_end(argv);

    ddtrace_sandbox_backup backup = ddtrace_sandbox_begin();
    ret = zend_call_function(fci, fcc);

    if (get_DD_TRACE_DEBUG()) {
        const char *scope, *colon, *name;
        dd_try_fetch_executing_function_name(call, &scope, &colon, &name);

        if (PG(last_error_message) && backup.eh.message != PG(last_error_message)) {
            char *error = ZSTR_VAL(PG(last_error_message));
#if PHP_VERSION_ID < 80100
            char *filename = PG(last_error_file);
#else
            char *filename = ZSTR_VAL(PG(last_error_file));
#endif
            ddtrace_log_errf("Error raised in ddtrace's closure for %s%s%s(): %s in %s on line %d", scope, colon, name,
                             error, filename, PG(last_error_lineno));
        }

        if (UNEXPECTED(EG(exception))) {
            zend_object *ex = EG(exception);

            const char *type = ZSTR_VAL(ex->ce->name);
            zend_string *msg = zai_exception_message(ex);
            ddtrace_log_errf("%s thrown in ddtrace's closure for %s%s%s(): %s", type, scope, colon, name,
                             ZSTR_VAL(msg));
        }
    }
    ddtrace_sandbox_end(&backup);

    zend_fcall_info_args_clear(fci, 1);

    return ret;
}

static void dd_load_deferred_integration(zend_class_entry *scope, zval *fname, ddtrace_dispatch_t **dispatch,
                                         HashTable *dispatch_table) {
    zval *integration = &(*dispatch)->deferred_load_integration_name;

    if (Z_TYPE_P(integration) == IS_NULL) {
        *dispatch = NULL;
        return;
    }

    // Protect against the free when we remove the dispatch from dispatch_table
    ddtrace_dispatch_copy(*dispatch);

    if (UNEXPECTED(FAILURE == zend_hash_del(dispatch_table, Z_STR((*dispatch)->function_name)))) {
        ddtrace_log_debugf("Failed to remove deferred dispatch for %s%s%s", ZSTR_VAL(scope->name), (scope ? "::" : ""),
                           Z_STRVAL_P(fname));
    }

    zval retval = {0};
    bool success = zai_call_function_literal("ddtrace\\integrations\\load_deferred_integration", &retval, integration);
    zval_ptr_dtor(&retval);

    ddtrace_dispatch_release(*dispatch);

    if (UNEXPECTED(!success)) {
        *dispatch = NULL;
        ddtrace_log_debugf(
            "Error loading deferred integration '%s' from DDTrace\\Integrations\\load_deferred_integration",
            Z_STRVAL_P(integration));
        return;
    }

    *dispatch = ddtrace_find_dispatch(scope, fname);
}

static bool dd_should_trace_helper(zend_execute_data *call, zend_function *fbc, ddtrace_dispatch_t **dispatch_ptr) {
    if (DDTRACE_G(class_lookup) == NULL || DDTRACE_G(function_lookup) == NULL) {
        return false;
    }

    // Don't trace closures or {main}/includes
    if ((fbc->common.fn_flags & ZEND_ACC_CLOSURE) || !fbc->common.function_name) {
        return false;
    }

    zend_class_entry *scope = dd_get_called_scope(call);
    zval fname = ddtrace_zval_zstr(fbc->common.function_name);
    ddtrace_dispatch_t *dispatch = NULL;
    HashTable *dispatch_table = NULL;

    bool found = ddtrace_try_find_dispatch(scope, &fname, &dispatch, &dispatch_table);

    if (found && (dispatch->options & DDTRACE_DISPATCH_DEFERRED_LOADER)) {
        dd_load_deferred_integration(scope, &fname, &dispatch, dispatch_table);
    }

    if (dispatch_ptr != NULL) {
        *dispatch_ptr = dispatch;
    }

    return dispatch != NULL;
}

static bool dd_should_trace_runtime(ddtrace_dispatch_t *dispatch) {
    // the callable can be NULL for ddtrace_known_integrations
    if (Z_TYPE(dispatch->callable) != IS_OBJECT && Z_TYPE(dispatch->callable) != IS_STRING) {
        return false;
    }

    if (dispatch->busy) {
        return false;
    }

    if (dispatch->options & (DDTRACE_DISPATCH_NON_TRACING)) {
        // non-tracing types should trigger regardless of limited tracing mode
        return true;
    }

    if ((ddtrace_tracer_is_limited() && (dispatch->options & DDTRACE_DISPATCH_INSTRUMENT_WHEN_LIMITED) == 0)) {
        return false;
    }
    return true;
}

#define DD_TRACE_COPY_NULLABLE_ARG(q)       \
    {                                       \
        if (Z_TYPE_INFO_P(q) != IS_UNDEF) { \
            Z_TRY_ADDREF_P(q);              \
        } else {                            \
            q = &EG(uninitialized_zval);    \
        }                                   \
        ZEND_HASH_FILL_ADD(q);              \
    }

static void dd_copy_args(zval *args, zend_execute_data *call) {
    uint32_t i, first_extra_arg;
    zval *p, *q;
    uint32_t arg_count = ZEND_CALL_NUM_ARGS(call);

    // @see https://github.com/php/php-src/blob/PHP-7.0/Zend/zend_builtin_functions.c#L506-L562
    array_init_size(args, arg_count);
    if (arg_count && call->func) {
        first_extra_arg = call->func->op_array.num_args;
        bool has_extra_args = arg_count > first_extra_arg;

        zend_hash_real_init_packed(Z_ARRVAL_P(args));
        ZEND_HASH_FILL_PACKED(Z_ARRVAL_P(args)) {
            i = 0;
            p = ZEND_CALL_ARG(call, 1);
            if (has_extra_args) {
                while (i < first_extra_arg) {
                    q = p;
                    DD_TRACE_COPY_NULLABLE_ARG(q);
                    p++;
                    i++;
                }
                if (call->func->type != ZEND_INTERNAL_FUNCTION) {
                    p = ZEND_CALL_VAR_NUM(call, call->func->op_array.last_var + call->func->op_array.T);
                }
            }
            while (i < arg_count) {
                q = p;
                DD_TRACE_COPY_NULLABLE_ARG(q);
                p++;
                i++;
            }
        }
        ZEND_HASH_FILL_END();
        Z_ARRVAL_P(args)->nNumOfElements = arg_count;
    }
}

void ddtrace_span_attach_exception(ddtrace_span_fci *span_fci, zend_object *exception) {
    zval *exception_zv = ddtrace_spandata_property_exception(&span_fci->span);
    if (exception && Z_TYPE_P(exception_zv) <= IS_FALSE && !zend_is_unwind_exit(exception)) {
        ZVAL_OBJ_COPY(exception_zv, exception);
    }
}

static bool dd_execute_tracing_closure(zval *callable, zval *span_data, zend_execute_data *call, zval *user_args,
                                       zval *user_retval, zend_object *exception) {
    bool keep_span = true;
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};
    zval rv;
    INIT_ZVAL(rv);
    zval exception_arg = {.u1.type_info = IS_NULL};
    if (exception) {
        ZVAL_OBJ(&exception_arg, exception);
    }
    zval *this = dd_call_this(call);

    if (!callable || !span_data || !user_args) {
        if (get_DD_TRACE_DEBUG()) {
            const char *fname = call->func ? ZSTR_VAL(call->func->common.function_name) : "{unknown}";
            ddtrace_log_errf("Tracing closure could not be run for %s() because it is in an invalid state", fname);
        }
        return false;
    }

    if (zend_fcall_info_init(callable, 0, &fci, &fcc, NULL, NULL) == FAILURE) {
        ddtrace_log_debug("Could not init tracing closure");
        return false;
    }

    if (this) {
        bool is_instance_method = (call->func && call->func->common.fn_flags & ZEND_ACC_STATIC) ? false : true;
        bool is_closure_static = (fcc.function_handler->common.fn_flags & ZEND_ACC_STATIC) ? true : false;
        if (is_instance_method && is_closure_static) {
            ddtrace_log_debug("Cannot trace non-static method with static tracing closure");
            return false;
        }
    }

    // Arg 2: mixed $retval
    if (!user_retval || Z_TYPE_INFO_P(user_retval) == IS_UNDEF) {
        user_retval = &EG(uninitialized_zval);
    }

    fci.retval = &rv;
    fcc.object = this ? Z_OBJ_P(this) : NULL;
    fcc.called_scope = dd_get_called_scope(call);
    // Give the tracing closure access to private & protected class members
    fcc.function_handler->common.scope = fcc.called_scope;

    ZEND_RESULT_CODE call_status =
        dd_sandbox_fci_call(call, &fci, &fcc, 4, span_data, user_args, user_retval, &exception_arg);
    if (UNEXPECTED(call_status != SUCCESS)) {
        ddtrace_log_debug("Could not execute tracing closure");
        keep_span = false;
    } else {
        keep_span = Z_TYPE(rv) != IS_FALSE;
    }

    zend_fcall_info_args_clear(&fci, 0);
    return keep_span;
}

static bool dd_call_sandboxed_tracing_closure(ddtrace_span_fci *span_fci, zval *callable, zval *user_retval) {
    zend_execute_data *call = span_fci->execute_data;
    ddtrace_span_t *span = &span_fci->span;
    zval user_args, span_zv;

    ZVAL_OBJ(&span_zv, &span->std);
    dd_copy_args(&user_args, call);

    bool keep_span = dd_execute_tracing_closure(callable, &span_zv, call, &user_args, user_retval, EG(exception));

    zval_dtor(&user_args);

    return keep_span;
}

static ZEND_RESULT_CODE dd_do_hook_function_prehook(zend_execute_data *call, ddtrace_dispatch_t *dispatch) {
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};

    char *error = NULL;
    if (zend_fcall_info_init(&dispatch->prehook, 0, &fci, &fcc, NULL, &error) != SUCCESS) {
        if (error) {
            ddtrace_log_debug(error);
            efree(error);
            error = NULL;
        }
        return FAILURE;
    }

    zval args = {.u1.type_info = IS_NULL};

    dd_copy_args(&args, call);

    zval retval = {.u1.type_info = IS_NULL};
    fci.retval = &retval;

    ZEND_RESULT_CODE status = dd_sandbox_fci_call(call, &fci, &fcc, 1, &args);

    zval_dtor(&retval);
    zval_dtor(&args);

    return status;
}

static ZEND_RESULT_CODE dd_do_hook_method_prehook(zend_execute_data *call, ddtrace_dispatch_t *dispatch) {
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};

    char *error = NULL;
    if (UNEXPECTED(zend_fcall_info_init(&dispatch->prehook, 0, &fci, &fcc, NULL, &error) != SUCCESS)) {
        if (error) {
            ddtrace_log_debug(error);
            efree(error);
            error = NULL;
        }
        return FAILURE;
    }

    zend_object *called_this = zend_get_this_object(call);

    zval This = {.u1.type_info = IS_NULL}, scope = {.u1.type_info = IS_NULL}, args = {.u1.type_info = IS_NULL};

    if (called_this) {
        ZVAL_OBJ(&This, called_this);
    }

    zend_class_entry *called_scope = zend_get_called_scope(call);
    if (called_scope) {
        ZVAL_STR(&scope, called_scope->name);
    }

    dd_copy_args(&args, call);

    zval retval = {.u1.type_info = IS_NULL};
    fci.retval = &retval;

    ZEND_RESULT_CODE status = dd_sandbox_fci_call(call, &fci, &fcc, 3, &This, &scope, &args);

    zval_dtor(&retval);
    zval_dtor(&args);

    return status;
}

static ddtrace_span_fci *dd_fcall_begin_tracing_hook(zend_execute_data *call, ddtrace_dispatch_t *dispatch) {
    ddtrace_span_fci *span_fci = ddtrace_init_span();
    span_fci->execute_data = call;
    span_fci->dispatch = dispatch;
    ddtrace_open_span(span_fci);

    return span_fci;
}

static ddtrace_span_fci *dd_fcall_begin_tracing_posthook(zend_execute_data *call, ddtrace_dispatch_t *dispatch) {
    return dd_fcall_begin_tracing_hook(call, dispatch);
}

static ddtrace_span_fci *dd_fcall_begin_tracing_prehook(zend_execute_data *call, ddtrace_dispatch_t *dispatch) {
    bool continue_tracing = true;
    ZEND_ASSERT(dispatch);

    ddtrace_span_fci *span_fci = dd_fcall_begin_tracing_hook(call, dispatch);
    ZEND_ASSERT(span_fci);

    zval user_retval = {.u1.type_info = IS_NULL};
    continue_tracing = dd_call_sandboxed_tracing_closure(span_fci, &dispatch->prehook, &user_retval);
    if (!continue_tracing) {
        ddtrace_drop_top_open_span();
        span_fci = NULL;
    }

    return span_fci;
}

static ddtrace_span_fci *dd_create_duplicate_span(zend_execute_data *call, ddtrace_dispatch_t *dispatch) {
    /* This is a hack. We put a span into the span stack as a tag for:
     *   1) close-at-exit posthook functionality
     *   2) prehook's dispatch needs to get released after original call
     * We want any children to be inherited by the currently active span, not
     * this fake one, so we duplicate the span_id.
     */
    ddtrace_span_fci *span_fci = ddtrace_init_span();
    span_fci->execute_data = call;
    span_fci->dispatch = dispatch;

    span_fci->next = DDTRACE_G(open_spans_top);
    DDTRACE_G(open_spans_top) = span_fci;

    ddtrace_span_t *span = &span_fci->span;

    span->trace_id = DDTRACE_G(trace_id);
    span->span_id = ddtrace_peek_span_id();

    // if you push a span_id of 0 it makes a new span id, which we don't want
    if (span->span_id) {
        ddtrace_push_span_id(span->span_id);
    }

    return span_fci;
}

static ddtrace_span_fci *dd_fcall_begin_non_tracing_posthook(zend_execute_data *call, ddtrace_dispatch_t *dispatch) {
    return dd_create_duplicate_span(call, dispatch);
}

static ddtrace_span_fci *dd_fcall_begin_non_tracing_prehook(zend_execute_data *call, ddtrace_dispatch_t *dispatch) {
    ZEND_ASSERT(dispatch);

    if (call->func->common.scope) {
        dd_do_hook_method_prehook(call, dispatch);
    } else {
        dd_do_hook_function_prehook(call, dispatch);
    }

    /* We need to keep the dispatch busy until the original call has returned,
     * so make a duplicate span.
     */
    return dd_create_duplicate_span(call, dispatch);
}

static ddtrace_span_fci *(*dd_fcall_begin[])(zend_execute_data *call, ddtrace_dispatch_t *dispatch) = {
    [DDTRACE_DISPATCH_POSTHOOK] = dd_fcall_begin_tracing_posthook,
    [DDTRACE_DISPATCH_POSTHOOK | DDTRACE_DISPATCH_NON_TRACING] = dd_fcall_begin_non_tracing_posthook,
    [DDTRACE_DISPATCH_PREHOOK] = dd_fcall_begin_tracing_prehook,
    [DDTRACE_DISPATCH_PREHOOK | DDTRACE_DISPATCH_NON_TRACING] = dd_fcall_begin_non_tracing_prehook,
};

static ddtrace_span_fci *dd_observer_begin(zend_execute_data *call, ddtrace_dispatch_t *dispatch) {
    uint16_t offset = DDTRACE_DISPATCH_JUMP_OFFSET(dispatch->options);

    ddtrace_dispatch_copy(dispatch);  // protecting against dispatch being freed during php code execution
    ddtrace_span_fci *span_fci = (dd_fcall_begin[offset])(call, dispatch);
    return span_fci;
}

void dd_set_fqn(zval *zv, zend_execute_data *ex) {
    if (!ex->func || !ex->func->common.function_name) {
        return;
    }
    zend_class_entry *called_scope = dd_get_called_scope(ex);
    if (called_scope) {
        // This cannot be cached on the dispatch since sub classes can share the same parent dispatch
        zend_string *fqn =
            strpprintf(0, "%s.%s", ZSTR_VAL(called_scope->name), ZSTR_VAL(ex->func->common.function_name));
        ZVAL_STR_COPY(zv, fqn);
        zend_string_release(fqn);
    } else {
        ZVAL_STR_COPY(zv, ex->func->common.function_name);
    }
}

static void dd_set_default_properties(void) {
    ddtrace_span_fci *span_fci = DDTRACE_G(open_spans_top);
    if (span_fci == NULL || span_fci->execute_data == NULL) {
        return;
    }

    ddtrace_span_t *span = &span_fci->span;
    // SpanData::$name defaults to fully qualified called name
    // The other span property defaults are set at serialization time
    zval *prop_name = ddtrace_spandata_property_name(span);
    if (prop_name && Z_TYPE_P(prop_name) <= IS_NULL) {
        zval prop_name_default;
        ZVAL_NULL(&prop_name_default);
        dd_set_fqn(&prop_name_default, span_fci->execute_data);
        ZVAL_COPY_VALUE(prop_name, &prop_name_default);
    }
}

static ZEND_RESULT_CODE dd_do_hook_method_posthook(zend_execute_data *call, ddtrace_dispatch_t *dispatch,
                                                   zval *user_retval) {
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};

    char *error = NULL;
    if (UNEXPECTED(zend_fcall_info_init(&dispatch->posthook, 0, &fci, &fcc, NULL, &error) != SUCCESS)) {
        if (error) {
            ddtrace_log_debug(error);
            efree(error);
            error = NULL;
        }
        return FAILURE;
    }

    zend_object *called_this = zend_get_this_object(call);

    zval This = ddtrace_zval_null(), scope = ddtrace_zval_null(), args = ddtrace_zval_null();
    zval tmp_retval = ddtrace_zval_null();

    if (called_this) {
        ZVAL_OBJ(&This, called_this);
    }

    zend_class_entry *called_scope = zend_get_called_scope(call);
    if (called_scope) {
        ZVAL_STR(&scope, called_scope->name);
    }

    dd_copy_args(&args, call);

    if (!user_retval) {
        user_retval = &tmp_retval;
    }

    zval retval = {.u1.type_info = IS_NULL};
    fci.retval = &retval;

    ZEND_RESULT_CODE status = dd_sandbox_fci_call(call, &fci, &fcc, 4, &This, &scope, &args, user_retval);

    zval_dtor(&retval);
    zval_dtor(&args);

    return status;
}

static ZEND_RESULT_CODE dd_do_hook_function_posthook(zend_execute_data *call, ddtrace_dispatch_t *dispatch,
                                                     zval *user_retval) {
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};

    char *error = NULL;
    if (zend_fcall_info_init(&dispatch->prehook, 0, &fci, &fcc, NULL, &error) != SUCCESS) {
        if (error) {
            ddtrace_log_debug(error);
            efree(error);
            error = NULL;
        }
        return FAILURE;
    }

    zval args = {.u1.type_info = IS_NULL}, tmp_retval = {.u1.type_info = IS_NULL};

    dd_copy_args(&args, call);

    if (!user_retval) {
        user_retval = &tmp_retval;
    }

    zval retval = {.u1.type_info = IS_NULL};
    fci.retval = &retval;

    ZEND_RESULT_CODE status = dd_sandbox_fci_call(call, &fci, &fcc, 2, &args, user_retval);

    zval_dtor(&retval);
    zval_dtor(&args);

    return status;
}

static void dd_fcall_end_tracing_posthook(ddtrace_span_fci *span_fci, zval *user_retval) {
    ddtrace_dispatch_t *dispatch = span_fci->dispatch;
    // TODO Remove this?
    ddtrace_span_attach_exception(span_fci, EG(exception));

    dd_trace_stop_span_time(&span_fci->span);

    bool keep_span = dd_call_sandboxed_tracing_closure(span_fci, &dispatch->posthook, user_retval);

    ddtrace_close_userland_spans_until(span_fci);  // because dropping / setting default properties happens on top span

    if (keep_span) {
        dd_set_default_properties();
        ddtrace_close_span(span_fci);
    } else {
        ddtrace_drop_top_open_span();
    }
}

static void dd_fcall_end_non_tracing_posthook(ddtrace_span_fci *span_fci, zval *user_retval) {
    zend_execute_data *call = span_fci->execute_data;
    ddtrace_dispatch_t *dispatch = span_fci->dispatch;

    if (call->func->common.scope) {
        dd_do_hook_method_posthook(call, dispatch, user_retval);
    } else {
        dd_do_hook_function_posthook(call, dispatch, user_retval);
    }

    // drop the placeholder span -- we do not need it
    ddtrace_drop_top_open_span();
}

static void dd_fcall_end_tracing_prehook(ddtrace_span_fci *span_fci, zval *user_retval) {
    UNUSED(user_retval);
    dd_trace_stop_span_time(&span_fci->span);

    ddtrace_close_userland_spans_until(span_fci);  // because setting default properties happens on top span

    dd_set_default_properties();
    ddtrace_close_span(span_fci);
}

static void dd_fcall_end_non_tracing_prehook(ddtrace_span_fci *span_fci, zval *user_retval) {
    UNUSED(span_fci, user_retval);

    ddtrace_drop_top_open_span();
}

static void (*dd_fcall_end[])(ddtrace_span_fci *span_fci, zval *user_retval) = {
    [DDTRACE_DISPATCH_POSTHOOK] = dd_fcall_end_tracing_posthook,
    [DDTRACE_DISPATCH_POSTHOOK | DDTRACE_DISPATCH_NON_TRACING] = dd_fcall_end_non_tracing_posthook,
    [DDTRACE_DISPATCH_PREHOOK] = dd_fcall_end_tracing_prehook,
    [DDTRACE_DISPATCH_PREHOOK | DDTRACE_DISPATCH_NON_TRACING] = dd_fcall_end_non_tracing_prehook,
};

static void dd_observer_end(zend_function *fbc, ddtrace_span_fci *span_fci, zval *user_retval) {
    if (ddtrace_has_top_internal_span(span_fci)) {
        ddtrace_dispatch_t *dispatch = span_fci->dispatch;
        uint16_t offset = DDTRACE_DISPATCH_JUMP_OFFSET(dispatch->options);
        (dd_fcall_end[offset])(span_fci, user_retval);
    } else if (fbc && get_DD_TRACE_DEBUG()) {
        ddtrace_log_errf("Cannot run tracing closure for %s(); spans out of sync", ZSTR_VAL(fbc->common.function_name));
    }
}

static zend_op *dd_get_next_catch_block(zend_op *opline) {
    if (opline->extended_value & ZEND_LAST_CATCH) {
        return NULL;
    }
    return OP_JMP_ADDR(opline, opline->op2);
}

static zend_class_entry *dd_get_catching_ce(zend_execute_data *execute_data, const zend_op *opline) {
    zend_class_entry *catch_ce = NULL;
    catch_ce = CACHED_PTR(opline->extended_value & ~ZEND_LAST_CATCH);
    if (catch_ce == NULL) {
        catch_ce =
            zend_fetch_class_by_name(Z_STR_P(RT_CONSTANT(opline, opline->op1)),
                                     Z_STR_P(RT_CONSTANT(opline, opline->op1) + 1), ZEND_FETCH_CLASS_NO_AUTOLOAD);
    }
    return catch_ce;
}

static bool dd_is_catching_frame(zend_execute_data *execute_data) {
    zend_class_entry *ce, *catch_ce;
    zend_try_catch_element *try_catch;
    const zend_op *throw_op = EG(opline_before_exception);
    uint32_t throw_op_num = throw_op - EX(func)->op_array.opcodes;
    int i, current_try_catch_offset = -1;

    // TODO Handle exceptions thrown because of loop var destruction on return/break/...
    // https://heap.space/xref/PHP-7.4/Zend/zend_vm_def.h?r=760faa12#7494-7503

    // TODO Handle exceptions thrown from generator context

    // Find the innermost try/catch block the exception was thrown in
    for (i = 0; i < EX(func)->op_array.last_try_catch; i++) {
        try_catch = &EX(func)->op_array.try_catch_array[i];
        if (try_catch->try_op > throw_op_num) {
            // Exception was thrown before any remaining try/catch blocks
            break;
        }
        if (throw_op_num < try_catch->catch_op) {
            current_try_catch_offset = i;
        }
        // Ignore "finally" (try_catch->finally_end)
    }

    while (current_try_catch_offset > -1) {
        try_catch = &EX(func)->op_array.try_catch_array[current_try_catch_offset];
        // Found a catch block
        if (throw_op_num < try_catch->catch_op) {
            zend_op *opline = &EX(func)->op_array.opcodes[try_catch->catch_op];
            // Travese all the catch blocks
            do {
                catch_ce = dd_get_catching_ce(execute_data, opline);
                if (catch_ce != NULL) {
                    ce = EG(exception)->ce;
                    if (ce == catch_ce || instanceof_function(ce, catch_ce)) {
                        return true;
                    }
                }
                opline = dd_get_next_catch_block(opline);
            } while (opline != NULL);
        }
        current_try_catch_offset--;
    }

    return false;
}

void ddtrace_close_all_open_spans(void) {
    ddtrace_span_fci *span_fci;
    while ((span_fci = DDTRACE_G(open_spans_top)) && (span_fci->execute_data != NULL || span_fci->next)) {
        if (span_fci->execute_data) {
            zval retval;
            ZVAL_NULL(&retval);
            dd_observer_end(NULL, span_fci, &retval);
        } else if (get_DD_AUTOFINISH_SPANS()) {
            dd_trace_stop_span_time(&span_fci->span);
            ddtrace_close_span(span_fci);
        } else {
            ddtrace_drop_top_open_span();
        }
    }
    DDTRACE_G(open_spans_top) = span_fci;
}

static void dd_observer_begin_handler(zend_execute_data *execute_data) {
    if (DDTRACE_G(disable_in_current_request)) {
        return;
    }
    ddtrace_dispatch_t *cached_dispatch = DDTRACE_OP_ARRAY_EXTENSION(&execute_data->func->op_array);
    if (!cached_dispatch || !dd_should_trace_runtime(cached_dispatch)) {
        return;
    }
    dd_observer_begin(execute_data, cached_dispatch);
}

static void dd_observer_end_handler(zend_execute_data *execute_data, zval *retval) {
    ddtrace_span_fci *span_fci = DDTRACE_G(open_spans_top);
    if (span_fci && span_fci->execute_data == execute_data) {
        if (EG(exception) && dd_is_catching_frame(execute_data) == false) {
            ddtrace_span_attach_exception(span_fci, EG(exception));
        }
        dd_observer_end(EX(func), span_fci, retval);
    }
}

zend_observer_fcall_handlers ddtrace_observer_fcall_init(zend_execute_data *execute_data) {
    zend_function *fbc = EX(func);
    if (DDTRACE_G(disable_in_current_request) || ddtrace_op_array_extension == 0 ||
        fbc->common.type != ZEND_USER_FUNCTION) {
        return (zend_observer_fcall_handlers){NULL, NULL};
    }

    ddtrace_dispatch_t *dispatch;
    if (dd_should_trace_helper(execute_data, fbc, &dispatch)) {
        DDTRACE_OP_ARRAY_EXTENSION(&fbc->op_array) = dispatch;
        return (zend_observer_fcall_handlers){dd_observer_begin_handler, dd_observer_end_handler};
    }
    return (zend_observer_fcall_handlers){NULL, NULL};
}

PHP_FUNCTION(ddtrace_internal_function_handler) {
    ddtrace_dispatch_t *dispatch;
    zend_function *fbc = EX(func);
    void (*handler)(INTERNAL_FUNCTION_PARAMETERS) = fbc->internal_function.reserved[ddtrace_resource];

    if (DDTRACE_G(disable_in_current_request)) {
        handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    if (dd_should_trace_helper(execute_data, fbc, &dispatch) && dd_should_trace_runtime(dispatch)) {
        ddtrace_span_fci *span_fci = dd_observer_begin(execute_data, dispatch);
        handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        if (span_fci) {
            dd_observer_end(fbc, span_fci, return_value);
        }
        return;
    }

    handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}
