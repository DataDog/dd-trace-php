#include <SAPI.h>
#include <exceptions/exceptions.h>
#include <php.h>

#include "engine_hooks.h"  // For 'ddtrace_resource'
#include "handlers_internal.h"
#include "logging.h"
#include "serializer.h"
#include "span.h"

// Keep in mind that we are currently not having special handling for uncaught exceptions thrown within the shutdown
// sequence. This arises from the exception handlers being only invoked at the end of {main}. Additionally we currently
// do not handle any exception thrown from an userland exception handler.
// Adding support for both is open to future scope, should the need arise.

static zend_class_entry dd_exception_or_error_handler_ce;
static zend_object_handlers dd_exception_or_error_handler_handlers;

static zval *dd_exception_or_error_handler_handler(zend_object *obj) { return OBJ_PROP_NUM(obj, 0); }

static void (*dd_header)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_http_response_code)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_set_error_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_set_exception_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_restore_exception_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

static void dd_check_exception_in_header(int old_response_code) {
    int new_response_code = SG(sapi_headers).http_response_code;

    if (!DDTRACE_G(open_spans_top)) {
        return;
    }

    if (old_response_code == new_response_code || new_response_code < 500) {
        return;
    }

    ddtrace_save_active_error_to_metadata();

    ddtrace_span_fci *root_span = DDTRACE_G(root_span);

    if (Z_TYPE_P(ddtrace_spandata_property_exception(&root_span->span)) > IS_FALSE) {
        return;
    }

    zend_execute_data *ex = EG(current_execute_data);
    do {
        if (ex->func && ZEND_USER_CODE(ex->func->type)) {
            // our goal is to avoid expensive and non-sideeffect free interaction with exceptions
            // we will thus by necessity not support the case where an exception has been thrown away
            // e.g. try { throw new \Exception; } catch { header("500"); } will NOT retrieve the exception
            // Similarly the exception freed in userland, e.g. via catch (\Exception $e) { unset($e); headers(...); }
            // will not have the exception available for us.
            // We want to *only* store exceptions from explicitly started spans and the root span.
            // In particular the lifetime of exception objects shall be in general not impacted, which they would be
            // if we were to opportunistically store every last thrown exception. We must avoid magically extending
            // the lifetime of arbitrary exceptions. Also this would have a hard time distinguishing between caught
            // and subsequently handled exceptions and headers set for unrelated reasons. We aim to not confuse
            // our users by feeding them misleading exceptions, which were thrown, but not necessarily related to
            // the observed behavior.
            // To achieve this we unwind the stack and check for still-alive exceptions in catch blocks.

            long op_num = ex->opline - ex->func->op_array.opcodes;
            for (int i = ex->func->op_array.last_try_catch - 1; i >= 0; --i) {
                zend_try_catch_element *try_catch = &ex->func->op_array.try_catch_array[i];

                if (!try_catch->catch_op || op_num < try_catch->catch_op) {
                    continue;
                }

                zend_op *catch_op = &ex->func->op_array.opcodes[try_catch->catch_op];

                // Every series of catch blocks is preceded by a single jump to the end of the catch blocks
                // i.e. the first opcode outside try/catch or the first opcode of finally
                // We need that information to distinguish whether we are in a catch block
                // However, opcache may eliminate this jump via its unreachable basic block elimination.
                // This mostly happens when a try block ends via a jump (goto, break, continue), return, exit or throw.
                // Given that we do not want to construct a CFG ourselves here (and in fact, even then, code which is
                // exclusively reachable after execution of the catch block is indistinguishable from code within the
                // catch block), we just assume that the nearest try/catch block with its final jump in the try block
                // eliminated is a valid candidate. Ultimately, we still check for the catch-variable holding an
                // exception.
                zend_op *pass_over_catchers_jump = catch_op - 1;
                if (pass_over_catchers_jump->opcode == ZEND_JMP &&
                    OP_JMP_ADDR(pass_over_catchers_jump, pass_over_catchers_jump->op1) < ex->opline) {
                    continue;
                }

                // Now iterate the individual catch blocks to find which one we are in and extract the CV
#if PHP_VERSION_ID < 70300
                while (catch_op->result.num == 0 && catch_op->extended_value < op_num) {
                    catch_op = &ex->func->op_array.opcodes[catch_op->extended_value];
                }

                zval *exception = ZEND_CALL_VAR(ex, catch_op->op2.var);
#else
                while (!(catch_op->extended_value & ZEND_LAST_CATCH) && catch_op->op2.opline_num < op_num) {
                    catch_op = &ex->func->op_array.opcodes[catch_op->op2.opline_num];
                }

                if (catch_op->result_type != IS_CV) {
                    break;
                }

                zval *exception = ZEND_CALL_VAR(ex, catch_op->result.var);
#endif

                ZVAL_DEREF(exception);
                if (Z_TYPE_P(exception) == IS_OBJECT &&
                    instanceof_function(Z_OBJ_P(exception)->ce, zend_ce_throwable)) {
                    ZVAL_COPY(ddtrace_spandata_property_exception(&root_span->span), exception);
                }

                // The final jump was eliminated from the current try  block, but possibly we are in a nested try/catch,
                // so continue searching here.
                if (pass_over_catchers_jump->opcode == ZEND_JMP) {
                    break;
                }
            }
        }
        ex = ex->prev_execute_data;
    } while (ex);
}

static PHP_FUNCTION(ddtrace_header) {
    int old_response_code = SG(sapi_headers).http_response_code;
    dd_header(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_check_exception_in_header(old_response_code);
}

static PHP_FUNCTION(ddtrace_http_response_code) {
    int old_response_code = SG(sapi_headers).http_response_code;
    dd_http_response_code(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_check_exception_in_header(old_response_code);
}

#if PHP_VERSION_ID < 70300
#define GC_IMMUTABLE (1 << 5)
#define GC_ADD_FLAGS(c, flag) GC_FLAGS(c) |= flag
#endif

// inject ourselves into a set_exception_handler
static void dd_wrap_exception_or_error_handler(zval *target, zend_bool is_error_handler) {
    zval wrapper;
    object_init_ex(&wrapper, &dd_exception_or_error_handler_ce);
    if (is_error_handler) {
        // this does not participate in GC, so fine to mark it that way, to distinguish from exception handler
        GC_ADD_FLAGS(Z_OBJ(wrapper), GC_IMMUTABLE);
    }
    Z_OBJ(wrapper)->handlers = &dd_exception_or_error_handler_handlers;
    ZVAL_COPY_VALUE(dd_exception_or_error_handler_handler(Z_OBJ(wrapper)), target);
    ZVAL_COPY_VALUE(target, &wrapper);
}

static void dd_set_exception_or_error_handler(zval *target, zval *old_handler, zend_bool is_error_handler) {
    if (!EG(exception)) {
        if (Z_TYPE_P(old_handler) == IS_OBJECT && Z_OBJCE_P(old_handler) == &dd_exception_or_error_handler_ce) {
            zval *handler = dd_exception_or_error_handler_handler(Z_OBJ_P(old_handler));
            Z_DELREF_P(old_handler);
            ZVAL_COPY(old_handler, handler);
            if (Z_ISUNDEF_P(old_handler)) {
                ZVAL_NULL(old_handler);
            }
        }
        dd_wrap_exception_or_error_handler(target, is_error_handler);
    }
}

static PHP_FUNCTION(ddtrace_set_error_handler) {
    dd_set_error_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_set_exception_or_error_handler(&EG(user_error_handler), return_value, true);
}

static PHP_FUNCTION(ddtrace_set_exception_handler) {
    dd_set_exception_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_set_exception_or_error_handler(&EG(user_exception_handler), return_value, false);
}

static PHP_FUNCTION(ddtrace_restore_exception_handler) {
    dd_restore_exception_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_ISUNDEF_P(&EG(user_exception_handler))) {
        dd_wrap_exception_or_error_handler(&EG(user_exception_handler), false);
    }
}

static zend_internal_function ddtrace_exception_or_error_handler;

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_exception_or_error_handler, 0, 0, 1)
ZEND_ARG_INFO(0, exception)
ZEND_END_ARG_INFO()

#if PHP_VERSION_ID < 70100
#define ZEND_STR_PREVIOUS "previous"
#endif

#if PHP_VERSION_ID < 70200 && !defined(__clang__)
// zpp API is not safe by itself, but our code is safe here.
#pragma GCC diagnostic ignored "-Wclobbered"
#endif
static PHP_METHOD(DDTrace_ExceptionOrErrorHandler, execute) {
    volatile zend_bool has_bailout = false;
    zend_bool is_error_handler = (GC_FLAGS(Z_OBJ_P(getThis())) & GC_IMMUTABLE) != 0;
    zval *handler = dd_exception_or_error_handler_handler(Z_OBJ_P(getThis()));

    if (is_error_handler) {
        zend_long type;
        zend_string *message;
        zval *error_filename;
        zend_long error_lineno;
        zval *symbol_table;

        ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_LONG(type)
        Z_PARAM_STR(message)
        Z_PARAM_ZVAL(error_filename)  // may be null
        Z_PARAM_LONG(error_lineno)
        Z_PARAM_ZVAL(symbol_table)  // may be null
        ZEND_PARSE_PARAMETERS_END();

        DDTRACE_G(active_error).type = (int)type;
        DDTRACE_G(active_error).message = message;

        if (!Z_ISUNDEF_P(handler)) {
            zval params[5];
            ZVAL_LONG(&params[0], type);
            ZVAL_STR(&params[1], message);
            ZVAL_COPY_VALUE(&params[2], error_filename);
            ZVAL_LONG(&params[3], error_lineno);
            ZVAL_COPY_VALUE(&params[4], symbol_table);

            zend_try {
                // remove ourselves from the stacktrace
                EG(current_execute_data) = execute_data->prev_execute_data;
                // this calls into PHP, but without sandbox, as we do not want to interfere with normal operation
                call_user_function(CG(function_table), NULL, handler, return_value, 5, params);
            }
            zend_catch { has_bailout = true; }
            zend_end_try();
        } else {
            ZVAL_FALSE(return_value);
        }

        DDTRACE_G(active_error).type = 0;
    } else {
        ddtrace_span_fci *root_span = DDTRACE_G(open_spans_top);
        zend_object *volatile exception;
        zval *volatile span_exception;
        volatile zval old_exception = {0};
        zval *exception_zv;

        ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT(exception_zv)
        ZEND_PARSE_PARAMETERS_END();

        exception = Z_OBJ_P(exception_zv);

        RETVAL_NULL();

        // Assign early so that exceptions thrown inside the exception handler won't gain priority
        if (root_span) {
            span_exception = ddtrace_spandata_property_exception(&root_span->span);
            ZVAL_COPY_VALUE((zval *)&old_exception, span_exception);
            GC_ADDREF(exception);
            ZVAL_OBJ(span_exception, exception);
        }

        // Evaluate whether we shall start some span here to show the exception handler

        zend_try {
            if (Z_ISUNDEF_P(handler)) {
                // Due to a bug in PHP 7 parse and compiler errors thrown in exception handlers are not properly handled
                // and emit an additional "Fatal error: Exception thrown without a stack frame in Unknown on line 0"
                // Work around by emitting the error ourselves instead of rethrowing.
                if (Z_OBJCE_P(exception_zv) == zend_ce_parse_error
#if PHP_VERSION_ID >= 70300
                    || Z_OBJCE_P(exception_zv) == zend_ce_compile_error
#endif
                ) {
                    GC_ADDREF(exception);
                    zend_exception_error(exception, E_ERROR);
                } else {
                    zend_throw_exception_internal(exception_zv);
                }
            } else {
                zval params[1];
                ZVAL_OBJ(&params[0], exception);

                // remove ourselves from the stack trace
                EG(current_execute_data) = execute_data->prev_execute_data;
                // this calls into PHP, but without sandbox, as we do not want to interfere with normal operation
                call_user_function(CG(function_table), NULL, handler, return_value, 1, params);
            }
        }
        zend_catch { has_bailout = true; }
        zend_end_try();

        // Now that we left the main interaction scope with userland:
        // we can attach this exception without visible user impact as previous exception
        // Note that the change will leak into shutdown sequence though, but this is a minor tradeoff we make here.
        // If this ever turns out to be problematic, we have to store it somewhere in DDTRACE_G()
        // and delay attaching until serialization.
        if (root_span && Z_TYPE_P((zval *)&old_exception) > IS_FALSE) {
            zval *previous = ZAI_EXCEPTION_PROPERTY(exception, ZEND_STR_PREVIOUS);
            while (Z_TYPE_P(previous) == IS_OBJECT && !Z_IS_RECURSIVE_P(previous) &&
                   instanceof_function(Z_OBJCE_P(previous), zend_ce_throwable)) {
                Z_PROTECT_RECURSION_P(previous);
                previous = ZAI_EXCEPTION_PROPERTY(Z_OBJ_P(previous), ZEND_STR_PREVIOUS);
            }

            if (Z_TYPE_P(previous) > IS_FALSE) {
                // okay, let's not touch this, there's a cycle
                GC_DELREF(exception);
                ZVAL_COPY_VALUE(span_exception, (zval *)&old_exception);
            } else {
                ZVAL_COPY_VALUE(previous, (zval *)&old_exception);
            }

            previous = ZAI_EXCEPTION_PROPERTY(exception, ZEND_STR_PREVIOUS);
            while (Z_TYPE_P(previous) == IS_OBJECT && Z_IS_RECURSIVE_P(previous)) {
                Z_UNPROTECT_RECURSION_P(previous);
                previous = ZAI_EXCEPTION_PROPERTY(Z_OBJ_P(previous), ZEND_STR_PREVIOUS);
            }
        }
    }

    if (has_bailout) {
        zend_bailout();
    }
}

static int dd_exception_handler_get_closure(zval *obj, zend_class_entry **ce_ptr, zend_function **fptr_ptr,
                                            zend_object **obj_ptr) {
    *fptr_ptr = (zend_function *)&ddtrace_exception_or_error_handler;
    *ce_ptr = &dd_exception_or_error_handler_ce;
    *obj_ptr = Z_OBJ_P(obj);
    return SUCCESS;
}

void ddtrace_exception_handlers_startup(void) {
    ddtrace_exception_or_error_handler = (zend_internal_function){
        .type = ZEND_INTERNAL_FUNCTION,
        .function_name = zend_new_interned_string(zend_string_init(ZEND_STRL("ddtrace_exception_handler"), 1)),
        .num_args = 1,
        .required_num_args = 1,
        .arg_info = (zend_internal_arg_info *)(arginfo_ddtrace_exception_or_error_handler + 1),
        .handler = &zim_DDTrace_ExceptionOrErrorHandler_execute,
    };

    INIT_NS_CLASS_ENTRY(dd_exception_or_error_handler_ce, "DDTrace", "ExceptionHandler", NULL);
    dd_exception_or_error_handler_ce.type = ZEND_INTERNAL_CLASS;
    zend_initialize_class_data(&dd_exception_or_error_handler_ce, false);
    dd_exception_or_error_handler_ce.info.internal.module = &ddtrace_module_entry;
    zend_declare_property_null(&dd_exception_or_error_handler_ce, "handler", sizeof("handler") - 1, ZEND_ACC_PUBLIC);
    memcpy(&dd_exception_or_error_handler_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    dd_exception_or_error_handler_handlers.get_closure = dd_exception_handler_get_closure;

    dd_zif_handler handlers[] = {
        {ZEND_STRL("header"), &dd_header, ZEND_FN(ddtrace_header)},
        {ZEND_STRL("http_response_code"), &dd_http_response_code, ZEND_FN(ddtrace_http_response_code)},
        {ZEND_STRL("set_error_handler"), &dd_set_error_handler, ZEND_FN(ddtrace_set_error_handler)},
        {ZEND_STRL("set_exception_handler"), &dd_set_exception_handler, ZEND_FN(ddtrace_set_exception_handler)},
        {ZEND_STRL("restore_exception_handler"), &dd_restore_exception_handler,
         ZEND_FN(ddtrace_restore_exception_handler)},
    };
    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    for (size_t i = 0; i < handlers_len; ++i) {
        dd_install_handler(handlers[i]);
    }

    if (ddtrace_resource != -1) {
        ddtrace_string handlers[] = {
            DDTRACE_STRING_LITERAL("header"),
            DDTRACE_STRING_LITERAL("http_response_code"),
            DDTRACE_STRING_LITERAL("set_error_handler"),
            DDTRACE_STRING_LITERAL("set_exception_handler"),
            DDTRACE_STRING_LITERAL("restore_exception_handler"),
        };
        size_t handlers_len = sizeof handlers / sizeof handlers[0];
        ddtrace_replace_internal_functions(CG(function_table), handlers_len, handlers);
    }
}

void ddtrace_exception_handlers_shutdown(void) { ddtrace_free_unregistered_class(&dd_exception_or_error_handler_ce); }

void ddtrace_exception_handlers_rinit(void) {
    if (Z_TYPE(EG(user_exception_handler)) != IS_OBJECT ||
        Z_OBJCE(EG(user_exception_handler)) != &dd_exception_or_error_handler_ce) {
        dd_wrap_exception_or_error_handler(&EG(user_exception_handler), false);
    }
}
