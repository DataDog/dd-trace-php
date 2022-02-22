#include <SAPI.h>
#include <exceptions/exceptions.h>
#include <php.h>

#include "handlers_internal.h"
#include "logging.h"
#include "serializer.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

// Keep in mind that we are currently not having special handling for uncaught exceptions thrown within the shutdown
// sequence. This arises from the exception handlers being only invoked at the end of {main}. Additionally we currently
// do not handle any exception thrown from an userland exception handler.
// Adding support for both is open to future scope, should the need arise.

static zend_class_entry dd_exception_or_error_handler_ce;
static zend_object_handlers dd_exception_or_error_handler_handlers;

typedef struct {
    zend_object std;
    zend_bool error;
    zval *wrapped;
} dd_exception_or_error_handler_t;

static dd_exception_or_error_handler_t *dd_exception_or_error_handler(zval *obj TSRMLS_DC) {
    return ((dd_exception_or_error_handler_t *)zend_object_store_get_object(obj TSRMLS_CC));
}

static void (*dd_header)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_http_response_code)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_set_error_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_set_exception_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*dd_restore_exception_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

static void dd_check_exception_in_header(int old_response_code TSRMLS_DC) {
    int new_response_code = SG(sapi_headers).http_response_code;

    if (!DDTRACE_G(open_spans_top)) {
        return;
    }

    if (old_response_code == new_response_code || new_response_code < 500) {
        return;
    }

    ddtrace_save_active_error_to_metadata(TSRMLS_C);

    ddtrace_span_fci *root_span = DDTRACE_G(root_span);

    zval *prop_exception = ddtrace_spandata_property_exception(&root_span->span);
    if (prop_exception != NULL && Z_TYPE_P(prop_exception) != IS_NULL &&
        (Z_TYPE_P(prop_exception) != IS_BOOL || Z_BVAL_P(prop_exception) != 0)) {
        return;
    }

    zend_execute_data *ex = EG(current_execute_data);
    do {
        if (ex->op_array) {
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

            long op_num = ex->opline - ex->op_array->opcodes;
            for (int i = ex->op_array->last_try_catch - 1; i >= 0; --i) {
                zend_try_catch_element *try_catch = &ex->op_array->try_catch_array[i];

                if (!try_catch->catch_op || op_num < try_catch->catch_op) {
                    continue;
                }

                zend_op *catch_op = &ex->op_array->opcodes[try_catch->catch_op];

                // Every series of catch blocks is preceded by a single jump to the end of the catch blocks
                // i.e. the first opcode outside try/catch or the first opcode of finally
                // We need that information to distinguish whether we are in a catch block
                zend_op *pass_over_catchers_jump = catch_op - 1;
                if (pass_over_catchers_jump->opcode != ZEND_JMP) {
                    ddtrace_log_errf(
                        "Our exception handling code is buggy, found unexpected opcode %d instead of a ZEND_JMP before "
                        "expected ZEND_CATCH (opcode %d)",
                        pass_over_catchers_jump->opcode, catch_op->opcode);
                    return;
                }

                if (pass_over_catchers_jump->op1.jmp_addr < ex->opline) {
                    continue;
                }

                // Now iterate the individual catch blocks to find which one we are in and extract the CV
                while (catch_op->result.num == 0 && (int)catch_op->extended_value < (int)op_num) {
                    catch_op = &ex->op_array->opcodes[catch_op->extended_value];
                }

#if PHP_VERSION_ID < 50500
#define EX_CV_NUM(ex, var) &(ex)->CVs[var]
#endif

                zval *exception = **EX_CV_NUM(ex, catch_op->op2.var);
                if (Z_TYPE_P(exception) == IS_OBJECT &&
                    instanceof_function(Z_OBJCE_P(exception), zend_exception_get_default(TSRMLS_C) TSRMLS_CC)) {
                    zval **prop = ddtrace_spandata_property_exception_write(&root_span->span);
                    if (*prop) {
                        zval_ptr_dtor(prop);
                    }

                    *prop = exception;
                    SEPARATE_ARG_IF_REF(*prop);
                }

                break;
            }
        }
        ex = ex->prev_execute_data;
    } while (ex);
}

static PHP_FUNCTION(ddtrace_header) {
    int old_response_code = SG(sapi_headers).http_response_code;
    dd_header(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_check_exception_in_header(old_response_code TSRMLS_CC);
}

static PHP_FUNCTION(ddtrace_http_response_code) {
    int old_response_code = SG(sapi_headers).http_response_code;
    dd_http_response_code(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_check_exception_in_header(old_response_code TSRMLS_CC);
}

// inject ourselves into a set_exception_handler
static void dd_wrap_exception_or_error_handler(zval **target, zend_bool is_error_handler TSRMLS_DC) {
    zval *wrapper;
    MAKE_STD_ZVAL(wrapper);
    object_init_ex(wrapper, &dd_exception_or_error_handler_ce);
    dd_exception_or_error_handler_t *obj = dd_exception_or_error_handler(wrapper TSRMLS_CC);
    obj->error = is_error_handler;
    obj->wrapped = *target;
    *target = wrapper;
}

static void dd_set_exception_or_error_handler(zval **target, zval *old_handler, zend_bool is_error_handler TSRMLS_DC) {
    if (!EG(exception)) {
        if (Z_TYPE_P(old_handler) == IS_OBJECT && Z_OBJCE_P(old_handler) == &dd_exception_or_error_handler_ce) {
            dd_exception_or_error_handler_t *obj = dd_exception_or_error_handler(old_handler TSRMLS_CC);
            if (obj->wrapped) {
                *old_handler = *obj->wrapped;
                zval_copy_ctor(obj->wrapped);
            } else {
                ZVAL_NULL(old_handler);
            }
        }
        dd_wrap_exception_or_error_handler(target, is_error_handler TSRMLS_CC);
    }
}

static PHP_FUNCTION(ddtrace_set_error_handler) {
    dd_set_error_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_set_exception_or_error_handler(&EG(user_error_handler), return_value, true TSRMLS_CC);
}

static PHP_FUNCTION(ddtrace_set_exception_handler) {
    dd_set_exception_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_set_exception_or_error_handler(&EG(user_exception_handler), return_value, false TSRMLS_CC);
}

static PHP_FUNCTION(ddtrace_restore_exception_handler) {
    dd_restore_exception_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (!EG(user_exception_handler)) {
        dd_wrap_exception_or_error_handler(&EG(user_exception_handler), false TSRMLS_CC);
    }
}

static zend_internal_function ddtrace_exception_or_error_handler;

ZEND_BEGIN_ARG_INFO_EX(arginfo_ddtrace_exception_or_error_handler, 0, 0, 1)
ZEND_ARG_INFO(0, exception)
ZEND_ARG_INFO(0, message)
ZEND_ARG_INFO(0, error_filename)
ZEND_ARG_INFO(0, error_lineno)
ZEND_ARG_INFO(1, context)
ZEND_END_ARG_INFO()

static PHP_METHOD(DDTrace_ExceptionOrErrorHandler, execute) {
    UNUSED(ht, return_value_used, return_value_ptr);

    volatile zend_bool has_bailout = false;
    dd_exception_or_error_handler_t *obj = dd_exception_or_error_handler(getThis() TSRMLS_CC);

    if (obj->error) {
        zval **args = (zval **)(zend_vm_stack_top(TSRMLS_C) - 6);

        DDTRACE_G(active_error).type = (int)Z_LVAL_P(args[0]);
        DDTRACE_G(active_error).message = args[1];

        // ensure RC=1 for weird functions taking any argument of the handler by ref even though they really shouldn't
        for (int i = 0; i < 5; ++i) {
            Z_DELREF_P(args[i]);
        }

        if (obj->wrapped) {
            zend_try {
                // remove ourselves from the stacktrace
                EG(current_execute_data) = EG(current_execute_data)->prev_execute_data;
                // this calls into PHP, but without sandbox, as we do not want to interfere with normal operation
                call_user_function(CG(function_table), NULL, obj->wrapped, return_value, 5, args TSRMLS_CC);
            }
            zend_catch { has_bailout = true; }
            zend_end_try();
        }

        for (int i = 0; i < 5; ++i) {
            Z_ADDREF_P(args[i]);
        }

        DDTRACE_G(active_error).type = 0;
    } else {
        ddtrace_span_fci *root_span = DDTRACE_G(open_spans_top);
        zval *exception, *volatile old_exception;

        zend_parse_parameters(1 TSRMLS_CC, "z", &exception);

        RETVAL_NULL();

        // Assign early so that exceptions thrown inside the exception handler won't gain priority
        if (root_span) {
            old_exception = ddtrace_spandata_property_exception(&root_span->span);
            Z_ADDREF_P(exception);
            *ddtrace_spandata_property_exception_write(&root_span->span) = exception;
        }

        // Evaluate whether we shall start some span here to show the exception handler

        zend_try {
            if (!obj->wrapped) {
                zend_throw_exception_object(exception TSRMLS_CC);
            } else {
                zval *params[1];
                params[0] = exception;

                // remove ourselves from the stack trace
                EG(current_execute_data) = EG(current_execute_data)->prev_execute_data;
                // this calls into PHP, but without sandbox, as we do not want to interfere with normal operation
                call_user_function(CG(function_table), NULL, obj->wrapped, return_value, 1, params TSRMLS_CC);
            }
        }
        zend_catch { has_bailout = true; }
        zend_end_try();

        // Now that we left the main interaction scope with userland:
        // we can attach this exception without visible user impact as previous exception
        // Note that the change will leak into shutdown sequence though, but this is a minor tradeoff we make here.
        // If this ever turns out to be problematic, we have to store it somewhere in DDTRACE_G()
        // and delay attaching until serialization.
        if (root_span && old_exception && Z_TYPE_P(old_exception) != IS_NULL &&
            (Z_TYPE_P(old_exception) != IS_BOOL || Z_BVAL_P(old_exception) != 0)) {
            zval *previous = ZAI_EXCEPTION_PROPERTY(exception, "previous");
            zval *top_exception = exception;
            while (Z_TYPE_P(previous) == IS_OBJECT && !Z_OBJPROP_P(previous)->nApplyCount &&
                   instanceof_function(Z_OBJCE_P(previous), zend_exception_get_default(TSRMLS_C) TSRMLS_CC)) {
                ++Z_OBJPROP_P(previous)->nApplyCount;
                top_exception = previous;
                previous = ZAI_EXCEPTION_PROPERTY(previous, "previous");
            }

            if (Z_TYPE_P(previous) != IS_NULL && Z_TYPE_P(previous) != IS_BOOL) {
                // okay, let's not touch this, there's a cycle
                Z_DELREF_P(exception);
                *ddtrace_spandata_property_exception_write(&root_span->span) = old_exception;
            } else {
                zend_exception_set_previous(top_exception, old_exception TSRMLS_CC);
            }

            previous = ZAI_EXCEPTION_PROPERTY(exception, "previous");
            while (Z_TYPE_P(previous) == IS_OBJECT && Z_OBJPROP_P(previous)->nApplyCount) {
                --Z_OBJPROP_P(previous)->nApplyCount;
                previous = ZAI_EXCEPTION_PROPERTY(previous, "previous");
            }
        }
    }

    if (has_bailout) {
        zend_bailout();
    }
}

static int dd_exception_handler_get_closure(zval *obj, zend_class_entry **ce_ptr, zend_function **fptr_ptr,
                                            zval **zobj_ptr TSRMLS_DC) {
    UNUSED(0 TSRMLS_CC);
    *fptr_ptr = (zend_function *)&ddtrace_exception_or_error_handler;
    *ce_ptr = &dd_exception_or_error_handler_ce;
    if (zobj_ptr) {
        *zobj_ptr = obj;
    }
    return SUCCESS;
}

static void dd_exception_or_error_handler_storage(void *object_ptr TSRMLS_DC) {
    dd_exception_or_error_handler_t *wrapper = (dd_exception_or_error_handler_t *)object_ptr;
    if (wrapper->wrapped) {
        zval_ptr_dtor(&wrapper->wrapped);
        wrapper->wrapped = NULL;
    }
    zend_objects_free_object_storage(object_ptr TSRMLS_CC);
}

static zend_object_value dd_exception_handler_create_object(zend_class_entry *class_type TSRMLS_DC) {
    zend_object *object = ecalloc(sizeof(dd_exception_or_error_handler_t), 1);
    object->ce = class_type;
    return (zend_object_value){
        .handle = zend_objects_store_put(object, (zend_objects_store_dtor_t)zend_objects_destroy_object,
                                         (zend_objects_free_object_storage_t)dd_exception_or_error_handler_storage,
                                         NULL TSRMLS_CC),
        .handlers = &dd_exception_or_error_handler_handlers,
    };
}

void ddtrace_exception_handlers_startup(TSRMLS_D) {
    ddtrace_exception_or_error_handler = (zend_internal_function){
        .type = ZEND_INTERNAL_FUNCTION,
        .function_name = "ddtrace_exception_handler",
        .num_args = 1,
        .required_num_args = 1,
        .arg_info = (zend_arg_info *)(arginfo_ddtrace_exception_or_error_handler + 1),
        .handler = &zim_DDTrace_ExceptionOrErrorHandler_execute,
    };

    INIT_NS_CLASS_ENTRY(dd_exception_or_error_handler_ce, "DDTrace", "ExceptionHandler", NULL);
    dd_exception_or_error_handler_ce.type = ZEND_INTERNAL_CLASS;
    dd_exception_or_error_handler_ce.create_object = dd_exception_handler_create_object;
    zend_initialize_class_data(&dd_exception_or_error_handler_ce, false TSRMLS_CC);
    dd_exception_or_error_handler_ce.info.internal.module = &ddtrace_module_entry;
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
        dd_install_handler(handlers[i] TSRMLS_CC);
    }
}

void ddtrace_exception_handlers_shutdown(void) { zend_hash_destroy(&dd_exception_or_error_handler_ce.properties_info); }

void ddtrace_exception_handlers_rinit(TSRMLS_D) {
    if (!EG(user_exception_handler) || Z_TYPE_P(EG(user_exception_handler)) != IS_OBJECT ||
        Z_OBJCE_P(EG(user_exception_handler)) != &dd_exception_or_error_handler_ce) {
        dd_wrap_exception_or_error_handler(&EG(user_exception_handler), false TSRMLS_CC);
    }
}
