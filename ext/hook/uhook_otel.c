#include <php.h>
#include <zend_closures.h>
#include <hook/hook.h>
#include "uhook.h"
#include "../configuration.h"
#include "../span.h"
#include <sandbox/sandbox.h>

#include <components/log/log.h>

typedef struct {
    zend_object *begin;
    zend_object *end;
} dd_uhook_def;

void dd_otel_call(zend_execute_data *execute_data, zval *retval, zend_object *closure, zval *rv) {
    zval args[8];

    int offset = retval ? 2 : 0;
    if (EX(func)->op_array.scope) {
        if (EX(func)->op_array.fn_flags & ZEND_ACC_STATIC) {
            ZVAL_STR(&args[0], zend_get_called_scope(execute_data)->name);
        } else {
            ZVAL_COPY_VALUE(&args[0], &EX(This));
        }
        ZVAL_STR(&args[2 + offset], EX(func)->op_array.scope->name);
    } else {
        ZVAL_NULL(&args[0]);
        ZVAL_NULL(&args[2 + offset]);
    }
    ZVAL_ARR(&args[1], dd_uhook_collect_args(execute_data));
    ZVAL_STR(&args[3 + offset], EX(func)->op_array.function_name);
    if (ZEND_USER_CODE(EX(func)->type)) {
        ZVAL_STR(&args[4 + offset], EX(func)->op_array.filename);
        ZVAL_LONG(&args[5 + offset], EX(func)->op_array.line_start);
    } else {
        ZVAL_NULL(&args[4 + offset]);
        ZVAL_NULL(&args[5 + offset]);
    }
    if (!retval) {
        ZVAL_EMPTY_ARRAY(&args[6]);
        ZVAL_EMPTY_ARRAY(&args[7]);
    } else {
        ZVAL_COPY_VALUE(&args[2], retval);
        if (EG(exception)) {
            ZVAL_OBJ(&args[3], EG(exception));
        } else {
            ZVAL_NULL(&args[3]);
        }
    }

    zend_fcall_info fci = empty_fcall_info;
    zend_fcall_info_cache fcc = empty_fcall_info_cache;

    zval zv;
    ZVAL_OBJ(&zv, closure);
    ZVAL_UNDEF(rv);

    zend_fcall_info_init(&zv, 0, &fci, &fcc, NULL, NULL);
    fci.retval = rv;
    fci.params = args;
    fci.param_count = 8;

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);
    if (zend_call_function(&fci, &fcc) == SUCCESS || PG(last_error_message)) {
        dd_uhook_report_sandbox_error(execute_data, closure);
    }
    zai_sandbox_close(&sandbox);

    zval_ptr_dtor(&args[1]);
}

static bool dd_uhook_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    UNUSED(invocation, dynamic);
    dd_uhook_def *def = auxiliary;

    LOGEV(HOOK_TRACE, dd_uhook_log_invocation(log, execute_data, "begin", def->begin););

    zval rv;
    dd_otel_call(execute_data, NULL, def->begin, &rv);

    if (Z_TYPE(rv) == IS_ARRAY) {
        zend_ulong arg_offset;
        zend_string *strkey;
        zval *val;
        zend_function *fbc = EX(func);

        ZEND_HASH_FOREACH_KEY_VAL(Z_ARR(rv), arg_offset, strkey, val) {
            bool found = false;
            if (strkey) {
                uint32_t num_args = fbc->common.num_args;
                // As per zend_handle_named_arg()
                if (EXPECTED(fbc->type == ZEND_USER_FUNCTION)
                    || EXPECTED(fbc->common.fn_flags & ZEND_ACC_USER_ARG_INFO)) {
                    for (uint32_t i = 0; i < num_args; i++) {
                        zend_arg_info *arg_info = &fbc->op_array.arg_info[i];
                        if (zend_string_equals(strkey, arg_info->name)) {
                            arg_offset = i;
                            found = true;
                        }
                    }
                } else {
                    for (uint32_t i = 0; i < num_args; i++) {
                        zend_internal_arg_info *arg_info = &fbc->internal_function.arg_info[i];
                        size_t len = strlen(arg_info->name);
                        if (zend_string_equals_cstr(strkey, arg_info->name, len)) {
                            arg_offset = i;
                            found = true;
                        }
                    }
                }
            } else {
                found = arg_offset < fbc->common.num_args;
            }

            uint32_t current_num_args = EX_NUM_ARGS();
            zval *arg = EX_VAR_NUM(arg_offset);
            if (!found) {
                if (!(fbc->common.fn_flags & ZEND_ACC_VARIADIC)) {
                    continue; // Unknown parameter
                }

                if (strkey) {
                    /* Unknown named parameter that will be collected into a variadic. */
                    if (!(EX_CALL_INFO() & ZEND_CALL_HAS_EXTRA_NAMED_PARAMS)) {
                        ZEND_ADD_CALL_FLAG(execute_data, ZEND_CALL_HAS_EXTRA_NAMED_PARAMS);
                        EX(extra_named_params) = zend_new_array(0);
                    }

                    Z_TRY_ADDREF_P(val);
                    zend_hash_update(EX(extra_named_params), strkey, val);
                    continue;
                } else {

                    if (arg_offset > current_num_args) {
                        if (!ZEND_USER_CODE(fbc->type)) {
                            if ((uint32_t)arg_offset - current_num_args > EG(vm_stack_end) - EX_VAR_NUM(0)) {
                                continue; // we won't mess with adding new frames
                            }
#if PHP_VERSION_ID >= 80200
                            // temporaries like the last observed frame are already initialized. Move them.
                            memmove(EX_VAR_NUM(arg_offset), EX_VAR_NUM(EX_NUM_ARGS()), fbc->common.T * sizeof(zval *));
#endif
                            for (uint32_t i = fbc->common.num_args; i < (uint32_t)arg_offset; ++i) {
                                ZVAL_UNDEF(EX_VAR_NUM(i));
                            }
                        } else {
                            arg = EX_VAR_NUM(fbc->op_array.last_var + fbc->op_array.T - fbc->op_array.num_args + arg_offset);
                            for (zval *extra_arg = EX_VAR_NUM(fbc->op_array.last_var + fbc->op_array.T); extra_arg < arg; ++extra_arg) {
                                ZVAL_UNDEF(extra_arg);
                            }
                        }
                    }
                }
            }
            if (arg_offset >= EX_NUM_ARGS()) {
                EX_NUM_ARGS() = arg_offset + 1;
                uint32_t num_extra_args = EX_NUM_ARGS() - current_num_args;

                if (num_extra_args > 1) {
                    for (uint32_t i = current_num_args; i < MIN(fbc->common.num_args, arg_offset); ++i) {
                        ZVAL_UNDEF(EX_VAR_NUM(i));
                    }
                    ZEND_ADD_CALL_FLAG(execute_data, ZEND_CALL_MAY_HAVE_UNDEF);
                }
            }
            zval_ptr_dtor(arg);
            ZVAL_COPY(arg, val);
        } ZEND_HASH_FOREACH_END();
    }
    zval_ptr_dtor(&rv);

    return true;
}

static void dd_uhook_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    UNUSED(invocation, dynamic);
    dd_uhook_def *def = auxiliary;

    LOGEV(HOOK_TRACE, dd_uhook_log_invocation(log, execute_data, "end", def->end););

    zval rv;
    dd_otel_call(execute_data, retval, def->end, &rv);

    if (!Z_ISUNDEF(rv)) {
        const zend_function *func = zend_get_closure_method_def(def->end);
        if (func->common.fn_flags & ZEND_ACC_HAS_RETURN_TYPE && (ZEND_TYPE_PURE_MASK(func->common.arg_info[-1].type) & IS_VOID) == 0) {
            zval_ptr_dtor(retval);
            ZVAL_COPY_VALUE(retval, &rv);
        } else {
            zval_ptr_dtor(&rv);
        }
    }
}

static void dd_uhook_dtor(void *data) {
    dd_uhook_def *def = data;
    if (def->begin) {
        OBJ_RELEASE(def->begin);
    }
    if (def->end) {
        OBJ_RELEASE(def->end);
    }
    efree(def);
}

PHP_FUNCTION(DDTrace_OpenTelemetry_Instrumentation_hook) {
    zend_string *class_name, *function_name;
    zval *pre = NULL, *post = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 4)
        Z_PARAM_STR_OR_NULL(class_name)
        Z_PARAM_STR(function_name)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(pre, zend_ce_closure)
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(post, zend_ce_closure)
    ZEND_PARSE_PARAMETERS_END();

    dd_uhook_def *def = emalloc(sizeof(*def));
    def->begin = pre ? Z_OBJ_P(pre) : NULL;
    if (def->begin) {
        GC_ADDREF(def->begin);
    }
    def->end = post ? Z_OBJ_P(post) : NULL;
    if (def->end) {
        GC_ADDREF(def->end);
    }

    zai_str class_str = ZAI_STR_EMPTY;
    if (class_name) {
        class_str = (zai_str)ZAI_STR_FROM_ZSTR(class_name);
    }
    zai_str func_str = ZAI_STR_FROM_ZSTR(function_name);


    bool success = zai_hook_install_generator(class_str, func_str,
                                              def->begin ? dd_uhook_begin : NULL, NULL, NULL, def->end ? dd_uhook_end : NULL,
                                              ZAI_HOOK_AUX(def, dd_uhook_dtor), 0) != -1;

    if (!success) {
        dd_uhook_dtor(def);
    } else {
        LOG(HOOK_TRACE, "Installing an otel hook function at %s:%d on %s %s%s%s",
            zend_get_executed_filename(), zend_get_executed_lineno(),
            class_name ? "method" : "function",
            class_name ? ZSTR_VAL(class_name) : "",
            class_name ? "::" : "",
            ZSTR_VAL(function_name));
    }
    RETURN_BOOL(success);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_OpenTelemetry_Instrumentation_hook, 0, 2, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, class, IS_STRING, 1)
    ZEND_ARG_TYPE_INFO(0, function, IS_STRING, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, pre, Closure, 1, "null")
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, post, Closure, 1, "null")
ZEND_END_ARG_INFO()

static zend_function_entry dd_otel_functions[] = {
    ZEND_NS_FALIAS("OpenTelemetry\\Instrumentation", hook, DDTrace_OpenTelemetry_Instrumentation_hook, arginfo_OpenTelemetry_Instrumentation_hook)
    ZEND_FE_END
};

zif_handler dd_extension_loaded;
ZEND_FUNCTION(DDTrace_extension_loaded) {
    dd_extension_loaded(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    if (Z_TYPE_P(return_value) == IS_FALSE && EX_NUM_ARGS() > 0 && Z_TYPE_P(EX_VAR_NUM(0)) == IS_STRING && zend_string_equals_cstr(Z_STR_P(EX_VAR_NUM(0)), ZEND_STRL("opentelemetry"))) {
        RETURN_TRUE;
    }
}

void dd_register_opentelemetry_wrapper(void) {
    if (!zend_hash_str_find_ptr_lc(CG(function_table), dd_otel_functions->fname, strlen(dd_otel_functions->fname))) {
        zend_register_functions(NULL, dd_otel_functions, NULL, MODULE_PERSISTENT);

        zend_internal_function *extension_loaded = zend_hash_str_find_ptr(CG(function_table), ZEND_STRL("extension_loaded"));
        dd_extension_loaded = extension_loaded->handler;
        extension_loaded->handler = ZEND_FN(DDTrace_extension_loaded);
    }
}
