#include "collect_backtrace.h"
#include <php.h>
#include <Zend/zend_generators.h>
#include <Zend/zend_attributes.h>
#include <Zend/zend_interfaces.h>
#include "compatibility.h"

// skip_args to not capture args if anyway captured via !DEBUG_BACKTRACE_IGNORE_ARGS
void ddtrace_call_get_locals(zend_execute_data *call, zval *locals_array, bool skip_args) {
    zend_op_array *op_array = &call->func->op_array;

    if (ZEND_CALL_INFO(call) & ZEND_CALL_HAS_SYMBOL_TABLE) {
        // array_dup also duplicates indirects away
        ZVAL_ARR(locals_array, zend_array_dup(call->symbol_table));
        if (!skip_args) {
            // But we want just locals, no args
            for (uint32_t i = 0; i < op_array->num_args; ++i) {
                zend_hash_del(Z_ARR_P(locals_array), op_array->vars[i]);
            }
        }
        return;
    }

    zend_array *locals = zend_new_array(op_array->last_var - op_array->num_args);
    for (int i = skip_args ? (int)op_array->num_args : 0; i < op_array->last_var; ++i) {
        zval *var = ZEND_CALL_VAR_NUM(call, i);
        Z_TRY_ADDREF_P(var);
        zend_hash_add_new(locals, op_array->vars[i], var);
    }
    ZVAL_ARR(locals_array, locals);
}

/* Copy from zend_builin_functions.c */
static void debug_backtrace_get_args(zend_execute_data *call, zval *arg_array) {
    uint32_t num_args = ZEND_CALL_NUM_ARGS(call);

    if (num_args) {
        uint32_t i = 0;
        zval *p = ZEND_CALL_ARG(call, 1);

        array_init_size(arg_array, num_args);
        zend_hash_real_init_packed(Z_ARRVAL_P(arg_array));
        ZEND_HASH_FILL_PACKED(Z_ARRVAL_P(arg_array)) {
            if (call->func->type == ZEND_USER_FUNCTION) {
                uint32_t first_extra_arg = MIN(num_args, call->func->op_array.num_args);

                if (UNEXPECTED(ZEND_CALL_INFO(call) & ZEND_CALL_HAS_SYMBOL_TABLE)) {
                    /* In case of attached symbol_table, values on stack may be invalid
                     * and we have to access them through symbol_table
                     * See: https://bugs.php.net/bug.php?id=73156
                     */
                    while (i < first_extra_arg) {
                        zend_string *arg_name = call->func->op_array.vars[i];
                        zval original_arg;
                        zval *arg = zend_hash_find_ex_ind(call->symbol_table, arg_name, 1);
                        zend_attribute *attribute = zend_get_parameter_attribute_str(
                                call->func->common.attributes,
                                "sensitiveparameter",
                                sizeof("sensitiveparameter") - 1,
                                i
                        );

                        bool is_sensitive = attribute != NULL;

                        if (arg) {
                            ZVAL_DEREF(arg);
                            ZVAL_COPY_VALUE(&original_arg, arg);
                        } else {
                            ZVAL_NULL(&original_arg);
                        }

                        if (is_sensitive) {
                            zval redacted_arg;
                            object_init_ex(&redacted_arg, zend_ce_sensitive_parameter_value);
                            zend_call_method_with_1_params(Z_OBJ_P(&redacted_arg), zend_ce_sensitive_parameter_value, &zend_ce_sensitive_parameter_value->constructor, "__construct", NULL, &original_arg);
                            ZEND_HASH_FILL_SET(&redacted_arg);
                        } else {
                            Z_TRY_ADDREF_P(&original_arg);
                            ZEND_HASH_FILL_SET(&original_arg);
                        }

                        ZEND_HASH_FILL_NEXT();
                        i++;
                    }
                } else {
                    while (i < first_extra_arg) {
                        zval original_arg;
                        zend_attribute *attribute = zend_get_parameter_attribute_str(
                                call->func->common.attributes,
                                "sensitiveparameter",
                                sizeof("sensitiveparameter") - 1,
                                i
                        );
                        bool is_sensitive = attribute != NULL;

                        if (EXPECTED(Z_TYPE_INFO_P(p) != IS_UNDEF)) {
                            zval *arg = p;
                            ZVAL_DEREF(arg);
                            ZVAL_COPY_VALUE(&original_arg, arg);
                        } else {
                            ZVAL_NULL(&original_arg);
                        }

                        if (is_sensitive) {
                            zval redacted_arg;
                            object_init_ex(&redacted_arg, zend_ce_sensitive_parameter_value);
                            zend_call_method_with_1_params(Z_OBJ_P(&redacted_arg), zend_ce_sensitive_parameter_value, &zend_ce_sensitive_parameter_value->constructor, "__construct", NULL, &original_arg);
                            ZEND_HASH_FILL_SET(&redacted_arg);
                        } else {
                            Z_TRY_ADDREF_P(&original_arg);
                            ZEND_HASH_FILL_SET(&original_arg);
                        }

                        ZEND_HASH_FILL_NEXT();
                        p++;
                        i++;
                    }
                }
                p = ZEND_CALL_VAR_NUM(call, call->func->op_array.last_var + call->func->op_array.T);
            }

            while (i < num_args) {
                zval original_arg;
                bool is_sensitive = 0;

                if (i < call->func->common.num_args || call->func->common.fn_flags & ZEND_ACC_VARIADIC) {
                    zend_attribute *attribute = zend_get_parameter_attribute_str(
                            call->func->common.attributes,
                            "sensitiveparameter",
                            sizeof("sensitiveparameter") - 1,
                            MIN(i, call->func->common.num_args)
                    );
                    is_sensitive = attribute != NULL;
                }

                if (EXPECTED(Z_TYPE_INFO_P(p) != IS_UNDEF)) {
                    zval *arg = p;
                    ZVAL_DEREF(arg);
                    ZVAL_COPY_VALUE(&original_arg, arg);
                } else {
                    ZVAL_NULL(&original_arg);
                }

                if (is_sensitive) {
                    zval redacted_arg;
                    object_init_ex(&redacted_arg, zend_ce_sensitive_parameter_value);
                    zend_call_method_with_1_params(Z_OBJ_P(&redacted_arg), zend_ce_sensitive_parameter_value, &zend_ce_sensitive_parameter_value->constructor, "__construct", NULL, &original_arg);
                    ZEND_HASH_FILL_SET(&redacted_arg);
                } else {
                    Z_TRY_ADDREF_P(&original_arg);
                    ZEND_HASH_FILL_SET(&original_arg);
                }

                ZEND_HASH_FILL_NEXT();
                p++;
                i++;
            }
        } ZEND_HASH_FILL_END();
        Z_ARRVAL_P(arg_array)->nNumOfElements = num_args;
    } else {
        ZVAL_EMPTY_ARRAY(arg_array);
    }

    if (ZEND_CALL_INFO(call) & ZEND_CALL_HAS_EXTRA_NAMED_PARAMS) {
        zend_string *name;
        zval *arg;
        SEPARATE_ARRAY(arg_array);
        ZEND_HASH_MAP_FOREACH_STR_KEY_VAL(call->extra_named_params, name, arg) {
            ZVAL_DEREF(arg);
            Z_TRY_ADDREF_P(arg);
            zend_hash_add_new(Z_ARRVAL_P(arg_array), name, arg);
        } ZEND_HASH_FOREACH_END();
    }
}

/* Copy of zend_fetch_debug_backtrace with ability to gather local variables */
void ddtrace_fetch_debug_backtrace(zval *return_value, int skip_last, int options, int limit)
{
    zend_execute_data *call;
    zend_object *object;
    bool fake_frame = 0;
    int lineno, frameno = 0;
    zend_function *func;
    zend_string *filename;
    zend_string *include_filename = NULL;
    zval tmp;
    HashTable *stack_frame, *prev_stack_frame = NULL;
    zend_string *key_locals = NULL;

    if (options & DDTRACE_DEBUG_BACKTRACE_CAPTURE_LOCALS) {
        key_locals = zend_string_init(ZEND_STRL("locals"), 0);
    }

    array_init(return_value);

    call = EG(current_execute_data);
    if (!call) {
        return;
    }

#if PHP_VERSION_ID >= 80300
    if (EG(filename_override)) {
        // Add the current execution point to the frame so we don't lose it
        zend_string *filename_override = EG(filename_override);
        zend_long lineno_override = EG(lineno_override);
        EG(filename_override) = NULL;
        EG(lineno_override) = -1;

        zend_string *filename = zend_get_executed_filename_ex();
        zend_long lineno = zend_get_executed_lineno();
        if (filename && (!zend_string_equals(filename, filename_override) || lineno != lineno_override)) {
            stack_frame = zend_new_array(8);
            zend_hash_real_init_mixed(stack_frame);
            ZVAL_STR_COPY(&tmp, filename);
            _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_FILE), &tmp, 1);
            ZVAL_LONG(&tmp, lineno);
            _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_LINE), &tmp, 1);
            ZVAL_STR_COPY(&tmp, ZSTR_KNOWN(ZEND_STR_CONST_EXPR_PLACEHOLDER));
            _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_FUNCTION), &tmp, 1);
            ZVAL_ARR(&tmp, stack_frame);
            zend_hash_next_index_insert_new(Z_ARRVAL_P(return_value), &tmp);
        }

        EG(filename_override) = filename_override;
        EG(lineno_override) = lineno_override;
    }
#endif

    if (skip_last) {
        /* skip debug_backtrace() */
        call = call->prev_execute_data;
    }

    while (call && (limit == 0 || frameno < limit)) {
        zend_execute_data *prev = call->prev_execute_data;

        if (!prev) {
            /* add frame for a handler call without {main} code */
            if (EXPECTED((ZEND_CALL_INFO(call) & ZEND_CALL_TOP_FUNCTION) == 0)) {
                break;
            }
        } else if (UNEXPECTED((ZEND_CALL_INFO(call) & ZEND_CALL_GENERATOR) != 0)) {
            prev = zend_generator_check_placeholder_frame(prev);
        }

#if PHP_VERSION_ID >= 80400
        /* For frameless calls we add an additional frame for the call itself. */
        if (ZEND_USER_CODE(call->func->type)) {
            const zend_op *opline = call->opline;
            if (!ZEND_OP_IS_FRAMELESS_ICALL(opline->opcode)) {
                goto not_frameless_call;
            }
            int num_args = ZEND_FLF_NUM_ARGS(opline->opcode);
            /* Check if any args were already freed. Skip the frame in that case. */
            if (num_args >= 1) {
                zval *arg = zend_get_zval_ptr(opline, opline->op1_type, &opline->op1, call);
                if (Z_TYPE_P(arg) == IS_UNDEF) goto not_frameless_call;
            }
            if (num_args >= 2) {
                zval *arg = zend_get_zval_ptr(opline, opline->op2_type, &opline->op2, call);
                if (Z_TYPE_P(arg) == IS_UNDEF) goto not_frameless_call;
            }
            if (num_args >= 3) {
                const zend_op *op_data = opline + 1;
                zval *arg = zend_get_zval_ptr(op_data, op_data->op1_type, &op_data->op1, call);
                if (Z_TYPE_P(arg) == IS_UNDEF) goto not_frameless_call;
            }
            stack_frame = zend_new_array(8);
            zend_hash_real_init_mixed(stack_frame);
            zend_function *func = ZEND_FLF_FUNC(opline);
            zend_string *name = func->common.function_name;
            ZVAL_STRINGL(&tmp, ZSTR_VAL(name), ZSTR_LEN(name));
            _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_FUNCTION), &tmp, 1);
            /* Steal file and line from the previous frame. */
            if (call->func && ZEND_USER_CODE(call->func->common.type)) {
                filename = call->func->op_array.filename;
                if (call->opline->opcode == ZEND_HANDLE_EXCEPTION) {
                    if (EG(opline_before_exception)) {
                        lineno = EG(opline_before_exception)->lineno;
                    } else {
                        lineno = call->func->op_array.line_end;
                    }
                } else {
                    lineno = call->opline->lineno;
                }
                ZVAL_STR_COPY(&tmp, filename);
                _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_FILE), &tmp, 1);
                ZVAL_LONG(&tmp, lineno);
                _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_LINE), &tmp, 1);
                if ((options & DDTRACE_DEBUG_BACKTRACE_CAPTURE_LOCALS)) {
                    ddtrace_call_get_locals(call, &tmp, (options & DEBUG_BACKTRACE_IGNORE_ARGS) == 0);
                    GC_ADDREF(key_locals);
                    _zend_hash_append_ex(stack_frame, key_locals, &tmp, 0);
                }
                if (prev_stack_frame) {
                    zend_hash_del(prev_stack_frame, ZSTR_KNOWN(ZEND_STR_FILE));
                    zend_hash_del(prev_stack_frame, ZSTR_KNOWN(ZEND_STR_LINE));
                    zend_hash_del(prev_stack_frame, key_locals);
                }
            }
            if ((options & DEBUG_BACKTRACE_IGNORE_ARGS) == 0) {
                HashTable *args = zend_new_array(8);
                zend_hash_real_init_mixed(args);
                if (num_args >= 1) {
                    zval *arg = zend_get_zval_ptr(opline, opline->op1_type, &opline->op1, call);
                    Z_TRY_ADDREF_P(arg);
                    zend_hash_next_index_insert_new(args, arg);
                }
                if (num_args >= 2) {
                    zval *arg = zend_get_zval_ptr(opline, opline->op2_type, &opline->op2, call);
                    Z_TRY_ADDREF_P(arg);
                    zend_hash_next_index_insert_new(args, arg);
                }
                if (num_args >= 3) {
                    const zend_op *op_data = opline + 1;
                    zval *arg = zend_get_zval_ptr(op_data, op_data->op1_type, &op_data->op1, call);
                    Z_TRY_ADDREF_P(arg);
                    zend_hash_next_index_insert_new(args, arg);
                }
                ZVAL_ARR(&tmp, args);
                _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_ARGS), &tmp, 1);
            }
            ZVAL_ARR(&tmp, stack_frame);
            zend_hash_next_index_insert_new(Z_ARRVAL_P(return_value), &tmp);
        }
        not_frameless_call:
#else
        UNUSED(prev_stack_frame);
#endif

        /* We use _zend_hash_append*() and the array must be preallocated */
        stack_frame = zend_new_array(8);
        zend_hash_real_init_mixed(stack_frame);

        if (prev && prev->func && ZEND_USER_CODE(prev->func->common.type)) {
            filename = prev->func->op_array.filename;
            if (prev->opline->opcode == ZEND_HANDLE_EXCEPTION) {
                if (EG(opline_before_exception)) {
                    lineno = EG(opline_before_exception)->lineno;
                } else {
                    lineno = prev->func->op_array.line_end;
                }
            } else {
                lineno = prev->opline->lineno;
            }
            ZVAL_STR_COPY(&tmp, filename);
            _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_FILE), &tmp, 1);
            ZVAL_LONG(&tmp, lineno);
            _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_LINE), &tmp, 1);
            if ((options & DDTRACE_DEBUG_BACKTRACE_CAPTURE_LOCALS)) {
                ddtrace_call_get_locals(prev, &tmp, (options & DEBUG_BACKTRACE_IGNORE_ARGS) == 0);
                GC_ADDREF(key_locals);
                _zend_hash_append_ex(stack_frame, key_locals, &tmp, 0);
            }

            /* try to fetch args only if an FCALL was just made - elsewise we're in the middle of a function
             * and debug_backtrace() might have been called by the error_handler. in this case we don't
             * want to pop anything of the argument-stack */
        } else {
            zend_execute_data *prev_call = prev;

            while (prev_call) {
                zend_execute_data *prev;

                if (prev_call &&
                    prev_call->func &&
                    !ZEND_USER_CODE(prev_call->func->common.type) &&
                    !(prev_call->func->common.fn_flags & ZEND_ACC_CALL_VIA_TRAMPOLINE)) {
                    break;
                }

                prev = prev_call->prev_execute_data;
                if (prev && prev->func && ZEND_USER_CODE(prev->func->common.type)) {
                    ZVAL_STR_COPY(&tmp, prev->func->op_array.filename);
                    _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_FILE), &tmp, 1);
                    ZVAL_LONG(&tmp, prev->opline->lineno);
                    _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_LINE), &tmp, 1);
                    if ((options & DDTRACE_DEBUG_BACKTRACE_CAPTURE_LOCALS)) {
                        ddtrace_call_get_locals(prev, &tmp, (options & DEBUG_BACKTRACE_IGNORE_ARGS) == 0);
                        GC_ADDREF(key_locals);
                        _zend_hash_append_ex(stack_frame, key_locals, &tmp, 0);
                    }
                    break;
                }
                prev_call = prev;
            }
            filename = NULL;
        }

        func = call->func;
        if (!fake_frame && func->common.function_name) {
            ZVAL_STR_COPY(&tmp, func->common.function_name);
            _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_FUNCTION), &tmp, 1);

            if (Z_TYPE(call->This) == IS_OBJECT) {
                object = Z_OBJ(call->This);
                /* $this may be passed into regular internal functions */
                if (func->common.scope) {
                    ZVAL_STR_COPY(&tmp, func->common.scope->name);
                } else if (object->handlers->get_class_name == zend_std_get_class_name) {
                    ZVAL_STR_COPY(&tmp, object->ce->name);
                } else {
                    ZVAL_STR(&tmp, object->handlers->get_class_name(object));
                }
                _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_CLASS), &tmp, 1);
                if ((options & DEBUG_BACKTRACE_PROVIDE_OBJECT) != 0) {
                    ZVAL_OBJ_COPY(&tmp, object);
                    _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_OBJECT), &tmp, 1);
                }

                ZVAL_INTERNED_STR(&tmp, ZSTR_KNOWN(ZEND_STR_OBJECT_OPERATOR));
                _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_TYPE), &tmp, 1);
            } else if (func->common.scope) {
                ZVAL_STR_COPY(&tmp, func->common.scope->name);
                _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_CLASS), &tmp, 1);
                ZVAL_INTERNED_STR(&tmp, ZSTR_KNOWN(ZEND_STR_PAAMAYIM_NEKUDOTAYIM));
                _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_TYPE), &tmp, 1);
            }

            if ((options & DEBUG_BACKTRACE_IGNORE_ARGS) == 0 &&
                func->type != ZEND_EVAL_CODE) {

                debug_backtrace_get_args(call, &tmp);
                _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_ARGS), &tmp, 1);
            }
        } else {
            /* i know this is kinda ugly, but i'm trying to avoid extra cycles in the main execution loop */
            bool build_filename_arg = 1;
            zend_string *pseudo_function_name;
            uint32_t include_kind = 0;
            if (prev && prev->func && ZEND_USER_CODE(prev->func->common.type) && prev->opline->opcode == ZEND_INCLUDE_OR_EVAL) {
                include_kind = prev->opline->extended_value;
            }

            switch (include_kind) {
                case ZEND_EVAL:
                    pseudo_function_name = ZSTR_KNOWN(ZEND_STR_EVAL);
                    build_filename_arg = 0;
                    break;
                case ZEND_INCLUDE:
                    pseudo_function_name = ZSTR_KNOWN(ZEND_STR_INCLUDE);
                    break;
                case ZEND_REQUIRE:
                    pseudo_function_name = ZSTR_KNOWN(ZEND_STR_REQUIRE);
                    break;
                case ZEND_INCLUDE_ONCE:
                    pseudo_function_name = ZSTR_KNOWN(ZEND_STR_INCLUDE_ONCE);
                    break;
                case ZEND_REQUIRE_ONCE:
                    pseudo_function_name = ZSTR_KNOWN(ZEND_STR_REQUIRE_ONCE);
                    break;
                default:
                    /* Skip dummy frame unless it is needed to preserve filename/lineno info. */
                    if (!filename) {
                        zend_array_destroy(stack_frame);
                        goto skip_frame;
                    }

                    pseudo_function_name = ZSTR_KNOWN(ZEND_STR_UNKNOWN);
                    build_filename_arg = 0;
                    break;
            }

            if (build_filename_arg && include_filename) {
                zval arg_array;

                array_init(&arg_array);

                /* include_filename always points to the last filename of the last last called-function.
                   if we have called include in the frame above - this is the file we have included.
                 */

                ZVAL_STR_COPY(&tmp, include_filename);
                zend_hash_next_index_insert_new(Z_ARRVAL(arg_array), &tmp);
                _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_ARGS), &arg_array, 1);
            }

            ZVAL_INTERNED_STR(&tmp, pseudo_function_name);
            _zend_hash_append_ex(stack_frame, ZSTR_KNOWN(ZEND_STR_FUNCTION), &tmp, 1);
        }

        ZVAL_ARR(&tmp, stack_frame);
        zend_hash_next_index_insert_new(Z_ARRVAL_P(return_value), &tmp);
        frameno++;
        prev_stack_frame = stack_frame;

        skip_frame:
        if (UNEXPECTED(ZEND_CALL_KIND(call) == ZEND_CALL_TOP_FUNCTION)
            && !fake_frame
            && prev
            && prev->func
            && ZEND_USER_CODE(prev->func->common.type)
            && prev->opline->opcode == ZEND_INCLUDE_OR_EVAL) {
            fake_frame = 1;
        } else {
            fake_frame = 0;
            include_filename = filename;
            call = prev;
        }

        if (key_locals) {
            zend_string_release(key_locals);
        }
    }
}
