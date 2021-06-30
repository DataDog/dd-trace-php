#include "ext/php7/engine_hooks.h"

#include <Zend/zend_closures.h>
#include <Zend/zend_compile.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_generators.h>
#include <Zend/zend_interfaces.h>
#include <exceptions/exceptions.h>
#include <functions/functions.h>
#include <stdbool.h>

#include "ext/php7/compatibility.h"
#include "ext/php7/ddtrace.h"
#include "ext/php7/dispatch.h"
#include "ext/php7/engine_api.h"
#include "ext/php7/logging.h"
#include "ext/php7/span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace)

int ddtrace_resource = -1;

#if PHP_VERSION_ID >= 70400
int ddtrace_op_array_extension = 0;
#endif

ZEND_TLS zend_function *dd_integrations_load_deferred_integration = NULL;

// True gloals; only modify in minit/mshutdown
static user_opcode_handler_t prev_ucall_handler;
static user_opcode_handler_t prev_fcall_handler;
static user_opcode_handler_t prev_fcall_by_name_handler;
static user_opcode_handler_t prev_return_handler;
static user_opcode_handler_t prev_return_by_ref_handler;
#if PHP_VERSION_ID >= 70100
static user_opcode_handler_t prev_yield_handler;
static user_opcode_handler_t prev_yield_from_handler;
#endif
static user_opcode_handler_t prev_handle_exception_handler;
static user_opcode_handler_t prev_exit_handler;

#if PHP_VERSION_ID < 70100
#define RETURN_VALUE_USED(opline) (!((opline)->result_type & EXT_TYPE_UNUSED))
#else
#define RETURN_VALUE_USED(opline) ((opline)->result_type != IS_UNUSED)
#endif

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
    ret = zend_fcall_info_argv(fci, argc, &argv);
    // The only way we mess this up is by passing in argc < 0
    ZEND_ASSERT(ret == SUCCESS);
    va_end(argv);

    ddtrace_sandbox_backup backup = ddtrace_sandbox_begin();
    ret = zend_call_function(fci, fcc);

    if (get_DD_TRACE_DEBUG()) {
        const char *scope, *colon, *name;
        dd_try_fetch_executing_function_name(call, &scope, &colon, &name);

        if (PG(last_error_message) && backup.eh.message != PG(last_error_message)) {
            char *error = PG(last_error_message);
            ddtrace_log_errf("Error raised in ddtrace's closure for %s%s%s(): %s in %s on line %d", scope, colon, name,
                             error, PG(last_error_file), PG(last_error_lineno));
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

#define DDTRACE_NOT_TRACED ((void *)1)

static bool dd_should_trace_helper(zend_execute_data *call, zend_function *fbc, ddtrace_dispatch_t **dispatch_ptr) {
    if (DDTRACE_G(class_lookup) == NULL || DDTRACE_G(function_lookup) == NULL) {
        return false;
    }

    // Don't trace closures or functions without names
    if ((fbc->common.fn_flags & ZEND_ACC_CLOSURE) || !fbc->common.function_name) {
        return false;
    }

    zval fname = ddtrace_zval_zstr(fbc->common.function_name);

    zend_class_entry *scope = dd_get_called_scope(call);

    ddtrace_dispatch_t *dispatch = NULL;
    HashTable *function_table = NULL;
    bool found = ddtrace_try_find_dispatch(scope, &fname, &dispatch, &function_table);
    if (found && dispatch->options & DDTRACE_DISPATCH_DEFERRED_LOADER) {
        if (Z_TYPE(dispatch->deferred_load_integration_name) != IS_NULL) {
            ddtrace_sandbox_backup backup = ddtrace_sandbox_begin();

            // protect against the free when we remove the dispatch from function_table
            ddtrace_dispatch_copy(dispatch);

            ZEND_RESULT_CODE deleted = zend_hash_del(function_table, Z_STR(dispatch->function_name));
            if (UNEXPECTED(deleted != SUCCESS)) {
                ddtrace_log_debugf("Failed to remove deferred dispatch for %s%s%s", ZSTR_VAL(scope->name),
                                   scope ? "::" : "", Z_STRVAL(fname));
            }

            zval retval = {.u1.type_info = IS_UNDEF};
            zval *integration = &dispatch->deferred_load_integration_name;
            zend_function **fn_proxy = &dd_integrations_load_deferred_integration;
            ddtrace_string loader = DDTRACE_STRING_LITERAL("ddtrace\\integrations\\load_deferred_integration");
            ZEND_RESULT_CODE status = ddtrace_call_function(fn_proxy, loader.ptr, loader.len, &retval, 1, integration);

            ddtrace_dispatch_release(dispatch);
            dispatch = EXPECTED(status == SUCCESS) ? ddtrace_find_dispatch(scope, &fname) : NULL;
            zval_ptr_dtor(&retval);

            ddtrace_sandbox_end(&backup);
        } else {
            dispatch = NULL;
        }
    }

    if (dispatch_ptr != NULL) {
        *dispatch_ptr = dispatch;
    }
    return dispatch;
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

static bool dd_should_trace_call(zend_execute_data *call, ddtrace_dispatch_t **dispatch) {
    zend_function *fbc = call->func;

    if (DDTRACE_G(disable_in_current_request)) {
        return false;
    }

#if PHP_VERSION_ID >= 70300
    /* From PHP 7.3's UPGRADING.INTERNALS:
     * Special purpose zend_functions marked by ZEND_ACC_CALL_VIA_TRAMPOLINE or
     * ZEND_ACC_FAKE_CLOSURE flags use reserved[0] for internal purpose.
     * Third party extensions must not modify reserved[] fields of these functions.
     *
     * On PHP 7.4, it seems to get used as well.
     */
    if (fbc->common.type == ZEND_USER_FUNCTION && ddtrace_resource != -1 &&
        !(fbc->common.fn_flags & (ZEND_ACC_CALL_VIA_TRAMPOLINE | ZEND_ACC_FAKE_CLOSURE))) {
        /* On PHP 7.4 the op_array reserved flag can only be set on compilation.
         * After that, you must use ZEND_OP_ARRAY_EXTENSION.
         * We don't use it at compile-time yet, so only check this on < 7.4.
         */
#if PHP_VERSION_ID < 70400
        if (fbc->op_array.reserved[ddtrace_resource] == DDTRACE_NOT_TRACED) {
            return false;
        }
#else
        ddtrace_dispatch_t *cached_dispatch = DDTRACE_OP_ARRAY_EXTENSION(&fbc->op_array);
        if (cached_dispatch == DDTRACE_NOT_TRACED) {
            return false;
        }
#endif

        if (!dd_should_trace_helper(call, fbc, dispatch)) {
#if PHP_VERSION_ID < 70400
            fbc->op_array.reserved[ddtrace_resource] = DDTRACE_NOT_TRACED;
#else
            DDTRACE_OP_ARRAY_EXTENSION(&fbc->op_array) = DDTRACE_NOT_TRACED;
#endif
            return false;
        }
        return dd_should_trace_runtime(*dispatch);
    }
#else
    if (fbc->common.type == ZEND_USER_FUNCTION && ddtrace_resource != -1) {
        if (fbc->op_array.reserved[ddtrace_resource] == DDTRACE_NOT_TRACED) {
            return false;
        }
        if (!dd_should_trace_helper(call, fbc, dispatch)) {
            fbc->op_array.reserved[ddtrace_resource] = DDTRACE_NOT_TRACED;
            return false;
        }
        return dd_should_trace_runtime(*dispatch);
    }
#endif
    return dd_should_trace_helper(call, fbc, dispatch) && dd_should_trace_runtime(*dispatch);
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

static void dd_copy_prehook_args(zval *args, zend_execute_data *call) {
    uint32_t i, first_extra_arg, extra_arg_count;
    zval *p, *q;
    uint32_t arg_count = ZEND_CALL_NUM_ARGS(call);

    // @see https://github.com/php/php-src/blob/PHP-7.0/Zend/zend_builtin_functions.c#L506-L562
    array_init_size(args, arg_count);
    if (arg_count && call->func) {
        first_extra_arg = call->func->op_array.num_args;
        bool has_extra_args = arg_count > first_extra_arg;

        zend_hash_real_init(Z_ARRVAL_P(args), 1);
        ZEND_HASH_FILL_PACKED(Z_ARRVAL_P(args)) {
            i = 0;
            p = ZEND_CALL_ARG(call, 1);
            if (has_extra_args) {
                i = arg_count - first_extra_arg;
            }
            while (i < arg_count) {
                q = p;
                DD_TRACE_COPY_NULLABLE_ARG(q);
                p++;
                i++;
            }
            /* If we are copying arguments before i_init_func_execute_data() has run, the extra agruments
               have not yet been moved to a separate array. */
            if (has_extra_args) {
                p = ZEND_CALL_VAR_NUM(call, first_extra_arg);
                extra_arg_count = arg_count - first_extra_arg;
                while (extra_arg_count--) {
                    q = p;
                    DD_TRACE_COPY_NULLABLE_ARG(q);
                    p++;
                }
            }
        }
        ZEND_HASH_FILL_END();
        Z_ARRVAL_P(args)->nNumOfElements = arg_count;
    }
}

static void dd_copy_posthook_args(zval *args, zend_execute_data *call) {
    uint32_t i, first_extra_arg;
    zval *p, *q;
    uint32_t arg_count = ZEND_CALL_NUM_ARGS(call);

    // @see https://github.com/php/php-src/blob/PHP-7.0/Zend/zend_builtin_functions.c#L506-L562
    array_init_size(args, arg_count);
    if (arg_count && call->func) {
        first_extra_arg = call->func->op_array.num_args;
        bool has_extra_args = arg_count > first_extra_arg;

        zend_hash_real_init(Z_ARRVAL_P(args), 1);
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

void ddtrace_span_attach_exception(ddtrace_span_fci *span_fci, ddtrace_exception_t *exception) {
    if (exception && span_fci->exception == NULL) {
        GC_ADDREF(exception);
        span_fci->exception = exception;
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

#if PHP_VERSION_ID < 70300
    fcc.initialized = 1;
#endif
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
    ddtrace_dispatch_t *dispatch = span_fci->dispatch;
    ddtrace_span_t *span = &span_fci->span;
    zval user_args, span_zv;

    ZVAL_OBJ(&span_zv, &span->std);
    void (*copy_args)(zval * args, zend_execute_data * call) =
        dispatch->options & DDTRACE_DISPATCH_PREHOOK ? dd_copy_prehook_args : dd_copy_posthook_args;
    copy_args(&user_args, call);

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

    dd_copy_prehook_args(&args, call);

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

    dd_copy_prehook_args(&args, call);

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
#if PHP_VERSION_ID < 70100
    /*
    For PHP < 7.1: The current execute_data gets replaced in the DO_FCALL handler and freed shortly
    afterward, so there is no way to track the execute_data that is allocated for a generator.
    */
    if ((call->func->common.fn_flags & ZEND_ACC_GENERATOR) != 0) {
        ddtrace_log_debug("Cannot instrument generators for PHP versions < 7.1");
        return NULL;
    }
#endif

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

    dd_copy_posthook_args(&args, call);

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

    dd_copy_posthook_args(&args, call);

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

static void dd_do_ucall_handler_impl(zend_execute_data *execute_data) {
    ddtrace_dispatch_t *dispatch = NULL;
    if (ZEND_DO_UCALL == EX(opline)->opcode && EX(call)->func && dd_should_trace_call(EX(call), &dispatch)) {
        dd_observer_begin(EX(call), dispatch);
    }
}

static bool dd_is_user_call(zend_execute_data *call) {
    ZEND_ASSUME(call != NULL);
    return call->func && call->func->type == ZEND_USER_FUNCTION;
}

static void dd_do_fcall_handler_impl(zend_execute_data *execute_data) {
    ddtrace_dispatch_t *dispatch = NULL;
    if (ZEND_DO_FCALL == EX(opline)->opcode && dd_is_user_call(EX(call)) && dd_should_trace_call(EX(call), &dispatch)) {
        dd_observer_begin(EX(call), dispatch);
    }
}

static void dd_do_fcall_by_name_handler_impl(zend_execute_data *execute_data) {
    ddtrace_dispatch_t *dispatch = NULL;
    bool opcode_matches = ZEND_DO_FCALL_BY_NAME == EX(opline)->opcode;
    if (opcode_matches && dd_is_user_call(EX(call)) && dd_should_trace_call(EX(call), &dispatch)) {
        dd_observer_begin(EX(call), dispatch);
    }
}

ZEND_HOT static int dd_do_ucall_handler(zend_execute_data *execute_data) {
    dd_do_ucall_handler_impl(execute_data);
    return ZEND_USER_OPCODE_DISPATCH;
}

ZEND_HOT static int dd_do_fcall_handler(zend_execute_data *execute_data) {
    dd_do_fcall_handler_impl(execute_data);
    return ZEND_USER_OPCODE_DISPATCH;
}

ZEND_HOT static int dd_do_fcall_by_name_handler(zend_execute_data *execute_data) {
    dd_do_fcall_by_name_handler_impl(execute_data);
    return ZEND_USER_OPCODE_DISPATCH;
}

ZEND_HOT static int dd_do_ucall_handler_with_prev(zend_execute_data *execute_data) {
    dd_do_ucall_handler_impl(execute_data);
    return prev_ucall_handler(execute_data);
}

ZEND_HOT static int dd_do_fcall_handler_with_prev(zend_execute_data *execute_data) {
    dd_do_fcall_handler_impl(execute_data);
    return prev_fcall_handler(execute_data);
}

ZEND_HOT static int dd_do_fcall_by_name_handler_with_prev(zend_execute_data *execute_data) {
    dd_do_fcall_by_name_handler_impl(execute_data);
    return prev_fcall_by_name_handler(execute_data);
}

static void dd_return_helper(zend_execute_data *execute_data) {
    ddtrace_span_fci *span_fci = DDTRACE_G(open_spans_top);
    if (span_fci && span_fci->execute_data == execute_data) {
        zval rv;
        zval *retval = NULL;
        switch (EX(opline)->op1_type) {
            case IS_CONST:
#if PHP_VERSION_ID >= 70300
                retval = RT_CONSTANT(EX(opline), EX(opline)->op1);
#else
                retval = EX_CONSTANT(EX(opline)->op1);
#endif
                break;
            case IS_TMP_VAR:
            case IS_VAR:
            case IS_CV:
                retval = EX_VAR(EX(opline)->op1.var);
                break;
                /* IS_UNUSED is NULL */
        }
        if (!retval || Z_TYPE_INFO_P(retval) == IS_UNDEF) {
            ZVAL_NULL(&rv);
            retval = &rv;
        }
        dd_observer_end(NULL, span_fci, retval);
    }
}

static void dd_return_handler_impl(zend_execute_data *execute_data) {
    if (ZEND_RETURN == EX(opline)->opcode) {
        dd_return_helper(execute_data);
    }
}

static int dd_return_handler(zend_execute_data *execute_data) {
    dd_return_handler_impl(execute_data);
    return ZEND_USER_OPCODE_DISPATCH;
}

static int dd_return_handler_with_prev(zend_execute_data *execute_data) {
    dd_return_handler_impl(execute_data);
    return prev_return_handler(execute_data);
}

static int dd_return_by_ref_handler(zend_execute_data *execute_data) {
    if (ZEND_RETURN_BY_REF == EX(opline)->opcode) {
        dd_return_helper(execute_data);
    }
    return prev_return_by_ref_handler ? prev_return_by_ref_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

#if PHP_VERSION_ID >= 70100
static void dd_yield_helper(zend_execute_data *execute_data) {
    ddtrace_span_fci *span_fci = DDTRACE_G(open_spans_top);
    /*
    Generators store their execute data on the heap and we lose the address to the original call
    so we grab the original address from the executor globals.
    */
    zend_execute_data *orig_ex = (zend_execute_data *)EG(vm_stack_top);
    if (span_fci && span_fci->execute_data == orig_ex) {
        zval rv;
        zval *retval = NULL;
        span_fci->execute_data = execute_data;
        switch (EX(opline)->op1_type) {
            case IS_CONST:
#if PHP_VERSION_ID >= 70300
                retval = RT_CONSTANT(EX(opline), EX(opline)->op1);
#else
                retval = EX_CONSTANT(EX(opline)->op1);
#endif
                break;
            case IS_TMP_VAR:
            case IS_VAR:
            case IS_CV:
                retval = EX_VAR(EX(opline)->op1.var);
                break;
                /* IS_UNUSED is NULL */
        }
        if (!retval || Z_TYPE_INFO_P(retval) == IS_UNDEF) {
            ZVAL_NULL(&rv);
            retval = &rv;
        }
        dd_observer_end(NULL, span_fci, retval);
    }
}

static int dd_yield_handler(zend_execute_data *execute_data) {
    if (ZEND_YIELD == EX(opline)->opcode) {
        dd_yield_helper(execute_data);
    }
    return prev_yield_handler ? prev_yield_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static int dd_yield_from_handler(zend_execute_data *execute_data) {
    if (ZEND_YIELD_FROM == EX(opline)->opcode) {
        dd_yield_helper(execute_data);
    }
    return prev_yield_from_handler ? prev_yield_from_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}
#endif

#if PHP_VERSION_ID < 70100
static zend_op *dd_get_next_catch_block(zend_execute_data *execute_data, zend_op *opline) {
    if (opline->result.num) {
        return NULL;
    }
    return &EX(func)->op_array.opcodes[opline->extended_value];
}
#elif PHP_VERSION_ID < 70300
static zend_op *dd_get_next_catch_block(zend_op *opline) {
    if (opline->result.num) {
        return NULL;
    }
    return ZEND_OFFSET_TO_OPLINE(opline, opline->extended_value);
}
#else
static zend_op *dd_get_next_catch_block(zend_op *opline) {
    if (opline->extended_value & ZEND_LAST_CATCH) {
        return NULL;
    }
    return OP_JMP_ADDR(opline, opline->op2);
}
#endif

static zend_class_entry *dd_get_catching_ce(zend_execute_data *execute_data, const zend_op *opline) {
    zend_class_entry *catch_ce = NULL;
#if PHP_VERSION_ID < 70300
    catch_ce = CACHED_PTR(Z_CACHE_SLOT_P(EX_CONSTANT(opline->op1)));
    if (catch_ce == NULL) {
        catch_ce = zend_fetch_class_by_name(Z_STR_P(EX_CONSTANT(opline->op1)), EX_CONSTANT(opline->op1) + 1,
                                            ZEND_FETCH_CLASS_NO_AUTOLOAD);
    }
#elif PHP_VERSION_ID < 70400
    catch_ce = CACHED_PTR(opline->extended_value & ~ZEND_LAST_CATCH);
    if (catch_ce == NULL) {
        catch_ce = zend_fetch_class_by_name(Z_STR_P(RT_CONSTANT(opline, opline->op1)),
                                            RT_CONSTANT(opline, opline->op1) + 1, ZEND_FETCH_CLASS_NO_AUTOLOAD);
    }
#else
    catch_ce = CACHED_PTR(opline->extended_value & ~ZEND_LAST_CATCH);
    if (catch_ce == NULL) {
        catch_ce =
            zend_fetch_class_by_name(Z_STR_P(RT_CONSTANT(opline, opline->op1)),
                                     Z_STR_P(RT_CONSTANT(opline, opline->op1) + 1), ZEND_FETCH_CLASS_NO_AUTOLOAD);
    }
#endif
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
#if PHP_VERSION_ID < 70100
                opline = dd_get_next_catch_block(execute_data, opline);
#else
                opline = dd_get_next_catch_block(opline);
#endif
            } while (opline != NULL);
        }
        current_try_catch_offset--;
    }

    return false;
}

static int dd_handle_exception_handler(zend_execute_data *execute_data) {
    ddtrace_span_fci *span_fci = DDTRACE_G(open_spans_top);
    if (ZEND_HANDLE_EXCEPTION == EX(opline)->opcode && span_fci && span_fci->execute_data == execute_data) {
        zval retval;
        ZVAL_NULL(&retval);
        // The catching frame's span will get closed by the return handler so we leave it open
        if (dd_is_catching_frame(execute_data) == false) {
            ddtrace_span_attach_exception(span_fci, EG(exception));
            dd_observer_end(NULL, span_fci, &retval);
        }
    }

    return prev_handle_exception_handler ? prev_handle_exception_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
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

static int dd_exit_handler(zend_execute_data *execute_data) {
    if (ZEND_EXIT == EX(opline)->opcode) {
        ddtrace_close_all_open_spans();
    }

    return prev_exit_handler ? prev_exit_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

void ddtrace_opcode_minit(void) {
    prev_ucall_handler = zend_get_user_opcode_handler(ZEND_DO_UCALL);
    prev_fcall_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL);
    prev_fcall_by_name_handler = zend_get_user_opcode_handler(ZEND_DO_FCALL_BY_NAME);

    user_opcode_handler_t fcall_handler = prev_fcall_handler ? dd_do_fcall_handler_with_prev : dd_do_fcall_handler;
    zend_set_user_opcode_handler(ZEND_DO_FCALL, fcall_handler);

    user_opcode_handler_t fcall_by_name_handler =
        prev_fcall_by_name_handler ? dd_do_fcall_by_name_handler_with_prev : dd_do_fcall_by_name_handler;
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, fcall_by_name_handler);

    user_opcode_handler_t ucall_handler = prev_ucall_handler ? dd_do_ucall_handler_with_prev : dd_do_ucall_handler;
    zend_set_user_opcode_handler(ZEND_DO_UCALL, ucall_handler);

    prev_return_handler = zend_get_user_opcode_handler(ZEND_RETURN);
    user_opcode_handler_t return_handler = prev_return_handler ? dd_return_handler_with_prev : dd_return_handler;
    zend_set_user_opcode_handler(ZEND_RETURN, return_handler);

    prev_return_by_ref_handler = zend_get_user_opcode_handler(ZEND_RETURN_BY_REF);
    zend_set_user_opcode_handler(ZEND_RETURN_BY_REF, dd_return_by_ref_handler);
#if PHP_VERSION_ID >= 70100
    prev_yield_handler = zend_get_user_opcode_handler(ZEND_YIELD);
    zend_set_user_opcode_handler(ZEND_YIELD, dd_yield_handler);
    prev_yield_from_handler = zend_get_user_opcode_handler(ZEND_YIELD_FROM);
    zend_set_user_opcode_handler(ZEND_YIELD_FROM, dd_yield_from_handler);
#endif
    prev_handle_exception_handler = zend_get_user_opcode_handler(ZEND_HANDLE_EXCEPTION);
    zend_set_user_opcode_handler(ZEND_HANDLE_EXCEPTION, dd_handle_exception_handler);
    prev_exit_handler = zend_get_user_opcode_handler(ZEND_EXIT);
    zend_set_user_opcode_handler(ZEND_EXIT, dd_exit_handler);
}

void ddtrace_opcode_mshutdown(void) {
    zend_set_user_opcode_handler(ZEND_DO_UCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL, NULL);
    zend_set_user_opcode_handler(ZEND_DO_FCALL_BY_NAME, NULL);

    zend_set_user_opcode_handler(ZEND_RETURN, NULL);
    zend_set_user_opcode_handler(ZEND_RETURN_BY_REF, NULL);
#if PHP_VERSION_ID >= 70100
    zend_set_user_opcode_handler(ZEND_YIELD, NULL);
    zend_set_user_opcode_handler(ZEND_YIELD_FROM, NULL);
#endif
    zend_set_user_opcode_handler(ZEND_HANDLE_EXCEPTION, NULL);
    zend_set_user_opcode_handler(ZEND_EXIT, NULL);
}

void ddtrace_execute_internal_minit(void) {}
void ddtrace_execute_internal_mshutdown(void) {}

void ddtrace_engine_hooks_rinit(void) { dd_integrations_load_deferred_integration = NULL; }
void ddtrace_engine_hooks_rshutdown(void) { dd_integrations_load_deferred_integration = NULL; }

PHP_FUNCTION(ddtrace_internal_function_handler) {
    ddtrace_dispatch_t *dispatch;
    void (*handler)(INTERNAL_FUNCTION_PARAMETERS) = EX(func)->internal_function.reserved[ddtrace_resource];

    if (!dd_should_trace_call(execute_data, &dispatch)) {
        handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    ddtrace_span_fci *span_fci = dd_observer_begin(execute_data, dispatch);
    handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    if (span_fci) {
        dd_observer_end(EX(func), span_fci, return_value);
    }
}

zend_object *ddtrace_make_exception_from_error(DDTRACE_ERROR_CB_PARAMETERS) {
    UNUSED(error_filename, error_lineno);

    zval ex;
    va_list args2;
    char message[1024];
    object_init_ex(&ex, ddtrace_ce_fatal_error);

    va_copy(args2, args);
    vsnprintf(message, sizeof(message), format, args2);
    va_end(args2);
    zval tmp = ddtrace_zval_stringl(message, strlen(message));
    zend_update_property(ddtrace_ce_fatal_error, &ex, "message", sizeof("message") - 1, &tmp);
    zval_ptr_dtor(&tmp);

    tmp = ddtrace_zval_long(type);
    zend_update_property(ddtrace_ce_fatal_error, &ex, "code", sizeof("code") - 1, &tmp);

    return Z_OBJ(ex);
}
