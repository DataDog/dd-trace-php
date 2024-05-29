#include <SAPI.h>
#include <exceptions/exceptions.h>
#include <php.h>

#include "collect_backtrace.h"
#include "configuration.h"
#include "engine_hooks.h"  // For 'ddtrace_resource'
#include "handlers_exception.h"
#include "handlers_internal.h"
#include "serializer.h"

// Keep in mind that we are currently not having special handling for uncaught exceptions thrown within the shutdown
// sequence. This arises from the exception handlers being only invoked at the end of {main}. Additionally we currently
// do not handle any exception thrown from an userland exception handler.
// Adding support for both is open to future scope, should the need arise.

static zend_class_entry dd_exception_or_error_handler_ce;
static zend_object_handlers dd_exception_handler_handlers;
static zend_object_handlers dd_error_handler_handlers;

static zval *dd_exception_or_error_handler_handler(zend_object *obj) { return OBJ_PROP_NUM(obj, 0); }

static zif_handler dd_header = NULL;
static zif_handler dd_http_response_code = NULL;
static zif_handler dd_set_error_handler = NULL;
static zif_handler dd_set_exception_handler = NULL;
static zif_handler dd_restore_exception_handler = NULL;

static void dd_check_exception_in_header(int old_response_code) {
    int new_response_code = SG(sapi_headers).http_response_code;

    if (!DDTRACE_G(active_stack)) {
        return;
    }

    ddtrace_root_span_data *root_span = DDTRACE_G(active_stack)->root_span;
    if (!root_span) {
        return;
    }

    if (old_response_code == new_response_code || new_response_code < 500) {
        return;
    }

    ddtrace_save_active_error_to_metadata();

    zval *root_exception = &root_span->property_exception;
    if (Z_TYPE_P(root_exception) > IS_FALSE) {
        return;
    }

    zend_object *ex = ddtrace_find_active_exception();
    if (ex) {
        ZVAL_OBJ_COPY(root_exception, ex);
        Z_PROP_FLAG_P(root_exception) = 2; // Re-assigning property values resets the property flag, which is very nice
    }
}

zend_object *ddtrace_find_active_exception(void) {
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
#if PHP_VERSION_ID < 70100
                while (catch_op->result.num == 0 && catch_op->extended_value < op_num) {
                    catch_op = &ex->func->op_array.opcodes[catch_op->extended_value];
                }
#else
                while (catch_op->result.num == 0 && ZEND_OFFSET_TO_OPLINE(catch_op, catch_op->extended_value) < ex->opline) {
                    catch_op = ZEND_OFFSET_TO_OPLINE(catch_op, catch_op->extended_value);
                }
#endif

                zval *exception = ZEND_CALL_VAR(ex, catch_op->op2.var);
#else
                while (!(catch_op->extended_value & ZEND_LAST_CATCH) && OP_JMP_ADDR(catch_op, catch_op->op2) < ex->opline) {
                    catch_op = OP_JMP_ADDR(catch_op, catch_op->op2);
                }

                if (catch_op->result_type != IS_CV) {
                    break;
                }

                zval *exception = ZEND_CALL_VAR(ex, catch_op->result.var);
#endif

                ZVAL_DEREF(exception);
                if (Z_TYPE_P(exception) == IS_OBJECT &&
                    instanceof_function(Z_OBJ_P(exception)->ce, zend_ce_throwable)) {
                    return Z_OBJ_P(exception);
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

    return NULL;
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

// inject ourselves into a set_exception_handler
static void dd_wrap_exception_or_error_handler(zval *target, zend_bool is_error_handler) {
    zval wrapper;
    object_init_ex(&wrapper, &dd_exception_or_error_handler_ce);
    Z_OBJ(wrapper)->handlers = is_error_handler ? &dd_error_handler_handlers : &dd_exception_handler_handlers;
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
ZEND_ARG_INFO(0, message)
ZEND_ARG_INFO(0, error_filename)
ZEND_ARG_INFO(0, error_lineno)
ZEND_END_ARG_INFO()

#if PHP_VERSION_ID < 70200 && !defined(__clang__)
// zpp API is not safe by itself, but our code is safe here.
#pragma GCC diagnostic ignored "-Wclobbered"
#endif
static PHP_METHOD(DDTrace_ExceptionOrErrorHandler, execute) {
    volatile zend_bool has_bailout = false;
    zend_bool is_error_handler = Z_OBJ_P(ZEND_THIS)->handlers == &dd_error_handler_handlers;
    zval *handler = dd_exception_or_error_handler_handler(Z_OBJ_P(ZEND_THIS));

    if (is_error_handler) {
        zend_long type;
        zend_string *message;
        zval *error_filename;
        zend_long error_lineno;
#if PHP_VERSION_ID < 80000
        zval *symbol_table;
        zval params[5];
        ZEND_PARSE_PARAMETERS_START(5, 5)
#else
        zval params[4];
        ZEND_PARSE_PARAMETERS_START(4, 4)
#endif
        Z_PARAM_LONG(type)
        Z_PARAM_STR(message)
        Z_PARAM_ZVAL(error_filename)  // may be null
        Z_PARAM_LONG(error_lineno)
#if PHP_VERSION_ID < 80000
        Z_PARAM_ZVAL(symbol_table)  // may be null
#endif
        ZEND_PARSE_PARAMETERS_END();

        DDTRACE_G(active_error).type = (int)type;
        DDTRACE_G(active_error).message = message;

        if (!Z_ISUNDEF_P(handler)) {
            ZVAL_LONG(&params[0], type);
            ZVAL_STR(&params[1], message);
            ZVAL_COPY_VALUE(&params[2], error_filename);
            ZVAL_LONG(&params[3], error_lineno);
#if PHP_VERSION_ID < 80000
            ZVAL_COPY_VALUE(&params[4], symbol_table);
#endif

            zend_try {
                // remove ourselves from the stacktrace
                EG(current_execute_data) = execute_data->prev_execute_data;
                // this calls into PHP, but without sandbox, as we do not want to interfere with normal operation
                call_user_function(CG(function_table), NULL, handler, return_value, sizeof(params) / sizeof(zval), params);
            }
            zend_catch { has_bailout = true; }
            zend_end_try();
        } else {
            ZVAL_FALSE(return_value);
        }

        DDTRACE_G(active_error).type = 0;
    } else {
        ddtrace_root_span_data *volatile root_span = DDTRACE_G(active_stack) ? DDTRACE_G(active_stack)->root_span : NULL;
        zend_object *volatile exception;
        zval *volatile span_exception;
        volatile zval old_exception = {0};

        ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJ(*((zend_object**)&exception))
        ZEND_PARSE_PARAMETERS_END();

        RETVAL_NULL();

        // Assign early so that exceptions thrown inside the exception handler won't gain priority
        if (root_span) {
            span_exception = &root_span->property_exception;
            ZVAL_COPY_VALUE((zval *)&old_exception, span_exception);
            ZVAL_OBJ_COPY(span_exception, exception);
        }

        // Evaluate whether we shall start some span here to show the exception handler

        zend_try {
            if (Z_ISUNDEF_P(handler)) {
#if PHP_VERSION_ID < 80000
                // Due to a bug in PHP 7 parse and compiler errors thrown in exception handlers are not properly handled
                // and emit an additional "Fatal error: Exception thrown without a stack frame in Unknown on line 0"
                // Work around by emitting the error ourselves instead of rethrowing.
                if (exception->ce == zend_ce_parse_error
#if PHP_VERSION_ID >= 70300
                    || exception->ce == zend_ce_compile_error
#endif
                ) {
                    GC_ADDREF(exception);
                    zend_exception_error(exception, E_ERROR);
                } else {
                    zval exception_zv;
                    ZVAL_OBJ(&exception_zv, exception);
                    zend_throw_exception_internal(&exception_zv);
                }
#else
                zend_throw_exception_internal(exception);
#endif
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
            zval *previous = zai_exception_read_property(exception, ZSTR_KNOWN(ZEND_STR_PREVIOUS));
            while (Z_TYPE_P(previous) == IS_OBJECT && !Z_IS_RECURSIVE_P(previous) &&
                   instanceof_function(Z_OBJCE_P(previous), zend_ce_throwable)) {
                Z_PROTECT_RECURSION_P(previous);
                previous = zai_exception_read_property(Z_OBJ_P(previous), ZSTR_KNOWN(ZEND_STR_PREVIOUS));
            }

            if (Z_TYPE_P(previous) > IS_FALSE) {
                // okay, let's not touch this, there's a cycle (or something weird)
                GC_DELREF(exception);
                ZVAL_COPY_VALUE(span_exception, (zval *)&old_exception);
            } else {
                ZVAL_COPY_VALUE(previous, (zval *)&old_exception);
            }

            previous = zai_exception_read_property(exception, ZSTR_KNOWN(ZEND_STR_PREVIOUS));
            while (Z_TYPE_P(previous) == IS_OBJECT && Z_IS_RECURSIVE_P(previous)) {
                Z_UNPROTECT_RECURSION_P(previous);
                previous = zai_exception_read_property(Z_OBJ_P(previous), ZSTR_KNOWN(ZEND_STR_PREVIOUS));
            }
        }
    }

    if (has_bailout) {
        zend_bailout();
    }
}

#if PHP_VERSION_ID < 80000
static int dd_exception_handler_get_closure(zval *obj_zv, zend_class_entry **ce_ptr, zend_function **fptr_ptr,
                                            zend_object **obj_ptr) {
    zend_object *obj = Z_OBJ_P(obj_zv);
#else
static
#if PHP_VERSION_ID < 80100
int
#else
zend_result
#endif
dd_exception_handler_get_closure(zend_object *obj, zend_class_entry **ce_ptr, zend_function **fptr_ptr,
                                            zend_object **obj_ptr, zend_bool check_only) {
    UNUSED(check_only);
#endif
    *obj_ptr = obj;
    *fptr_ptr = (zend_function *)&ddtrace_exception_or_error_handler;
    *ce_ptr = &dd_exception_or_error_handler_ce;
    return SUCCESS;
}

#if PHP_VERSION_ID >= 80100
void dd_exception_handler_freed(zend_object *object) {
    zend_object_std_dtor(object);

    if (!EG(current_execute_data) && get_DD_TRACE_ENABLED()) {
        // Here we are at the very last chance before objects are unconditionally freed.
        // Let's force-disable the tracing in case it wasn't yet
        // Typically RSHUTDOWN would handle that, but since 8.1.0 opcache will free our objects before module_shutdown during preloading
        dd_force_shutdown_tracing();
    }
}
#endif

static zend_object *ddtrace_exception_new(zend_class_entry *class_type, zend_object *(*prev)(zend_class_entry *class_type)) {
    zend_execute_data *ex = EG(current_execute_data);
    EG(current_execute_data) = NULL;
    zend_object *object = prev(class_type);
    EG(current_execute_data) = ex;

    zend_class_entry *base_ce = zai_get_exception_base(object);

    bool ignore_args = false;
#if PHP_VERSION_ID >= 70400
    ignore_args = EG(exception_ignore_args);
#endif

    zval trace;
    ddtrace_fetch_debug_backtrace(&trace, 0, (ignore_args ? DEBUG_BACKTRACE_IGNORE_ARGS : 0) | DDTRACE_DEBUG_BACKTRACE_CAPTURE_LOCALS, 0);
    Z_SET_REFCOUNT(trace, 0);
    zend_update_property_ex(base_ce, object, ZSTR_KNOWN(ZEND_STR_TRACE), &trace);

    zval tmp;
    zend_string *filename;
    if ((class_type != zend_ce_parse_error
#if PHP_VERSION_ID >= 70300
                && class_type != zend_ce_compile_error
#endif
            ) || !(filename = zend_get_compiled_filename())) {
        ZVAL_STRING(&tmp, zend_get_executed_filename());
        zend_update_property_ex(base_ce, object, ZSTR_KNOWN(ZEND_STR_FILE), &tmp);
        zval_ptr_dtor(&tmp);
        ZVAL_LONG(&tmp, zend_get_executed_lineno());
        zend_update_property_ex(base_ce, object, ZSTR_KNOWN(ZEND_STR_LINE), &tmp);
    } else {
        ZVAL_STR(&tmp, filename);
        zend_update_property_ex(base_ce, object, ZSTR_KNOWN(ZEND_STR_FILE), &tmp);
        ZVAL_LONG(&tmp, zend_get_compiled_lineno());
        zend_update_property_ex(base_ce, object, ZSTR_KNOWN(ZEND_STR_LINE), &tmp);
    }

    if (ex && ex->func && ZEND_USER_CODE(ex->func->type)) {
        ddtrace_call_get_locals(ex, &tmp, !ignore_args);
        zend_string *key_locals = zend_string_init(ZEND_STRL("locals"), 0);
        Z_SET_REFCOUNT(tmp, 0);
        zend_update_property_ex(base_ce, object, key_locals, &tmp);
        zend_string_release(key_locals);
    }

    return object;
}

// fast path
zend_object *(*prev_exception_default_create_object)(zend_class_entry *class_type);
static zend_object *ddtrace_default_exception_new(zend_class_entry *class_type) {
    return ddtrace_exception_new(class_type, prev_exception_default_create_object);
}

// support custom exception create handlers
HashTable ddtrace_exception_custom_create_object;
static zend_object *ddtrace_custom_exception_new(zend_class_entry *class_type) {
    zend_class_entry *ce = class_type;
    zend_object *(*prev)(zend_class_entry *class_type);
    while (!(prev = zend_hash_index_find_ptr(&ddtrace_exception_custom_create_object, (zend_long)(uintptr_t)ce))) {
        ce = ce->parent;
    }
    return ddtrace_exception_new(class_type, prev);
}

static zend_property_info *dd_add_exception_locals_property(zend_class_entry *ce) {
    zend_string *key = zend_string_init(ZEND_STRL("locals"), 1);
#if PHP_VERSION_ID >= 80000
    zend_property_info *prop = zend_declare_typed_property(ce, key, &EG(uninitialized_zval), ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ARRAY));
#else
    zend_declare_property_ex(ce, key, &EG(uninitialized_zval), ZEND_ACC_PRIVATE, NULL);
    zend_property_info *prop = zend_hash_find_ptr(&ce->properties_info, key);
#endif
    zend_string_release(key);
    return prop;
}

void ddtrace_exception_handlers_startup(void) {
    ddtrace_exception_or_error_handler = (zend_internal_function){
        .type = ZEND_INTERNAL_FUNCTION,
        .function_name = zend_string_init_interned(ZEND_STRL("ddtrace_exception_handler"), 1),
        .num_args = 4,
        .required_num_args = 1,
        .arg_info = (zend_internal_arg_info *)(arginfo_ddtrace_exception_or_error_handler + 1),
        .handler = &zim_DDTrace_ExceptionOrErrorHandler_execute,
    };

    INIT_NS_CLASS_ENTRY(dd_exception_or_error_handler_ce, "DDTrace", "ExceptionHandler", NULL);
    dd_exception_or_error_handler_ce.type = ZEND_INTERNAL_CLASS;
    zend_initialize_class_data(&dd_exception_or_error_handler_ce, false);
    dd_exception_or_error_handler_ce.info.internal.module = &ddtrace_module_entry;
    zend_declare_property_null(&dd_exception_or_error_handler_ce, "handler", sizeof("handler") - 1, ZEND_ACC_PUBLIC);
    memcpy(&dd_error_handler_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    dd_error_handler_handlers.get_closure = dd_exception_handler_get_closure;
    memcpy(&dd_exception_handler_handlers, &dd_error_handler_handlers, sizeof(zend_object_handlers));
#if PHP_VERSION_ID >= 80100
    dd_exception_handler_handlers.free_obj = dd_exception_handler_freed;
#endif

    datadog_php_zif_handler handlers[] = {
        {ZEND_STRL("header"), &dd_header, ZEND_FN(ddtrace_header)},
        {ZEND_STRL("http_response_code"), &dd_http_response_code, ZEND_FN(ddtrace_http_response_code)},
        {ZEND_STRL("set_error_handler"), &dd_set_error_handler, ZEND_FN(ddtrace_set_error_handler)},
        {ZEND_STRL("set_exception_handler"), &dd_set_exception_handler, ZEND_FN(ddtrace_set_exception_handler)},
        {ZEND_STRL("restore_exception_handler"), &dd_restore_exception_handler,
         ZEND_FN(ddtrace_restore_exception_handler)},
    };
    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    for (size_t i = 0; i < handlers_len; ++i) {
        datadog_php_install_handler(handlers[i]);
    }

    zend_property_info *exception_prop = dd_add_exception_locals_property(zend_ce_exception);
    zend_property_info *error_prop = dd_add_exception_locals_property(zend_ce_error);

    prev_exception_default_create_object = zend_ce_exception->create_object;
    zend_hash_init(&ddtrace_exception_custom_create_object, 8, NULL, NULL, 1);
    zend_class_entry *ce;
    zend_string *locals_key = zend_string_init_interned(ZEND_STRL("locals"), 1);
    ZEND_HASH_FOREACH_PTR(CG(class_table), ce) {
        if ((ce->ce_flags & ZEND_ACC_INTERFACE) == 0 && instanceof_function_slow(ce, zend_ce_throwable)) {
            if (ce->create_object) {
                if (ce->create_object == prev_exception_default_create_object) {
                    ce->create_object = ddtrace_default_exception_new;
                } else {
                    zend_hash_index_add_ptr(&ddtrace_exception_custom_create_object, (zend_long)(uintptr_t)ce, ce->create_object);
                    ce->create_object = ddtrace_custom_exception_new;
                }

                // add locals property to all existing throwables
                zend_class_entry *base_ce = NULL;
                zend_property_info *parent_info;
                if (ce != zend_ce_exception && instanceof_function_slow(ce, zend_ce_exception)) {
                    base_ce = zend_ce_exception;
                    parent_info = exception_prop;
                } else if (ce != zend_ce_error && instanceof_function_slow(ce, zend_ce_error)) {
                    base_ce = zend_ce_error;
                    parent_info = error_prop;
                }
                if (base_ce) {
                    zval *child = zend_hash_find_known_hash(&ce->properties_info, locals_key);
                    if (child) {
                        ((zend_property_info *)Z_PTR_P(child))->flags |= ZEND_ACC_CHANGED;
                    } else {
                        zend_hash_add_new_ptr(&ce->properties_info, locals_key, parent_info);
                    }

                    zend_property_info *property_info;
                    ZEND_HASH_MAP_FOREACH_PTR(&ce->properties_info, property_info) {
                        if (property_info->ce == ce && (property_info->flags & ZEND_ACC_STATIC) == 0) {
                            property_info->offset += sizeof(zval);
                        }
                    } ZEND_HASH_FOREACH_END();

                    int insert_at = zend_hash_num_elements(&base_ce->properties_info) - 1;
                    ce->default_properties_count++;

                    ce->default_properties_table = perealloc(ce->default_properties_table, sizeof(zval) * ce->default_properties_count, 1);
                    memmove(ce->default_properties_table + insert_at + 1, ce->default_properties_table + insert_at, sizeof(zval) * (ce->default_properties_count - insert_at - 1));
                    ZVAL_COPY_VALUE_PROP(ce->default_properties_table + insert_at, base_ce->default_properties_table + insert_at);

#if PHP_VERSION_ID >= 70400
                    ce->properties_info_table = perealloc(ce->properties_info_table, sizeof(zend_property_info *) * ce->default_properties_count, 1);
                    memmove(ce->properties_info_table + insert_at + 1, ce->properties_info_table + insert_at, sizeof(zend_property_info *) * (ce->default_properties_count - insert_at - 1));
                    ce->properties_info_table[insert_at] = parent_info;
#endif
                }
            }
        }
    } ZEND_HASH_FOREACH_END();
}

void ddtrace_exception_handlers_shutdown(void) { ddtrace_free_unregistered_class(&dd_exception_or_error_handler_ce); }

void ddtrace_exception_handlers_rinit(void) {
    if (Z_TYPE(EG(user_exception_handler)) != IS_OBJECT ||
        Z_OBJCE(EG(user_exception_handler)) != &dd_exception_or_error_handler_ce) {
        dd_wrap_exception_or_error_handler(&EG(user_exception_handler), false);
    }
}
