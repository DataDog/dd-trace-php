#include <Zend/zend_compile.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_vm.h>
#include "../../hook/hook.h"

static void zai_interceptor_add_new_entries(HashPosition classpos, HashPosition funcpos) {
    zend_string *lcname;
    zend_ulong index;

    zend_hash_move_forward_ex(CG(class_table), &classpos); // move past previous end
    for (zend_class_entry *ce;
            (ce = zend_hash_get_current_data_ptr_ex(CG(class_table), &classpos));
            zend_hash_move_forward_ex(CG(class_table), &classpos)) {
        zend_hash_get_current_key_ex(CG(class_table), &lcname, &index, &classpos);
        zai_hook_resolve_class(ce, lcname);
    }

    zend_hash_move_forward_ex(CG(function_table), &funcpos); // move past previous end
    for (zend_function *func;
            (func = zend_hash_get_current_data_ptr_ex(CG(function_table), &funcpos));
            zend_hash_move_forward_ex(CG(function_table), &funcpos)) {
        zend_hash_get_current_key_ex(CG(function_table), &lcname, &index, &funcpos);
        zai_hook_resolve_function(func, lcname);
    }
}

static zend_op_array *(*prev_compile_file)(zend_file_handle *file_handle, int type);
static zend_op_array *zai_interceptor_compile_file(zend_file_handle *file_handle, int type) {
    HashPosition classpos, funcpos;
    zend_hash_internal_pointer_end_ex(CG(class_table), &classpos);
    uint32_t class_iter = zend_hash_iterator_add(CG(class_table), classpos);
    zend_hash_internal_pointer_end_ex(CG(function_table), &funcpos);
    uint32_t func_iter = zend_hash_iterator_add(CG(function_table), funcpos);

    zend_op_array *op_array = prev_compile_file(file_handle, type);

    classpos = zend_hash_iterator_pos(class_iter, CG(class_table));
    funcpos = zend_hash_iterator_pos(func_iter, CG(function_table));

    zai_interceptor_add_new_entries(classpos, funcpos);

    zend_hash_iterator_del(class_iter);
    zend_hash_iterator_del(func_iter);

    return op_array;
}

#if PHP_VERSION_ID < 80200
#define ZAI_COMPILE_STRING_ARGS zend_string *source_string, const char *filename
#define ZAI_COMPILE_STRING_PASSTHRU source_string, filename
#else
#define ZAI_COMPILE_STRING_ARGS zend_string *source_string, const char *filename, zend_compile_position position
#define ZAI_COMPILE_STRING_PASSTHRU source_string, filename, position
#endif

static zend_op_array *(*prev_compile_string)(ZAI_COMPILE_STRING_ARGS);
static zend_op_array *zai_interceptor_compile_string(ZAI_COMPILE_STRING_ARGS) {
    HashPosition classpos, funcpos;
    zend_hash_internal_pointer_end_ex(CG(class_table), &classpos);
    zend_hash_internal_pointer_end_ex(CG(function_table), &funcpos);

    zend_op_array *op_array = prev_compile_string(ZAI_COMPILE_STRING_PASSTHRU);

    zai_interceptor_add_new_entries(classpos, funcpos);

    return op_array;
}

static void (*prev_class_alias)(INTERNAL_FUNCTION_PARAMETERS);
PHP_FUNCTION(zai_interceptor_resolve_after_class_alias) {
    prev_class_alias(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    if (Z_TYPE_P(return_value) == IS_TRUE) {
        HashPosition pos;
        zend_string *lcname;
        zend_ulong index;
        zend_hash_internal_pointer_end_ex(CG(class_table), &pos);
        zend_class_entry *ce = zend_hash_get_current_data_ptr_ex(CG(class_table), &pos);
        zend_hash_get_current_key_ex(CG(class_table), &lcname, &index, &pos);
        zai_hook_resolve_class(ce, lcname);
    }
}

// random 8 bit number greater than ZEND_VM_LAST_OPCODE, but with index in zend_spec_handlers
#define ZAI_INTERCEPTOR_POST_DECLARE_OP (ZEND_VM_LAST_OPCODE + 1)

static zend_op zai_interceptor_post_declare_op;
static __thread zend_op zai_interceptor_post_declare_ops[4];
struct zai_interceptor_opline { const zend_op *op; struct zai_interceptor_opline *prev; };
static __thread struct zai_interceptor_opline zai_interceptor_opline_before_binding = {0};
static void zai_interceptor_install_post_declare_op(zend_execute_data *execute_data) {
    // We replace the current opline *before* it is executed. Thus we need to preserve opline data first:
    //  only the second opline can be our own opcode.
    zend_op *opline = &zai_interceptor_post_declare_ops[0];
    *opline = *EX(opline);
    zai_interceptor_post_declare_ops[1] = zai_interceptor_post_declare_op;
    // literals are opline-relative and thus need to be relocated
    zval *constant = (zval *)&zai_interceptor_post_declare_ops[2];
    if (opline->op1_type == IS_CONST) {
        // DECLARE_* ops all have two consecutive literals in op1
        ZVAL_COPY_VALUE(&constant[0], RT_CONSTANT(EX(opline), opline->op1));
        ZVAL_COPY_VALUE(&constant[1], RT_CONSTANT(EX(opline), opline->op1) + 1);
        opline->op1.constant = sizeof(zend_op) * 2;
    }
    if (opline->op2_type == IS_CONST) {
        ZVAL_COPY_VALUE(&constant[2], RT_CONSTANT(EX(opline), opline->op2));
        ZVAL_COPY_VALUE(&constant[3], RT_CONSTANT(EX(opline), opline->op2) + 1);
        opline->op2.constant = sizeof(zend_op) * 2 + sizeof(zval) * 2;
    }

    if (zai_interceptor_opline_before_binding.op) {
        struct zai_interceptor_opline *backup = ecalloc(1, sizeof(*zai_interceptor_opline_before_binding.prev));
        *backup = zai_interceptor_opline_before_binding;
        zai_interceptor_opline_before_binding.prev = backup;
    }
    zai_interceptor_opline_before_binding.op = EX(opline);
    EX(opline) = zai_interceptor_post_declare_ops;
}

static void zai_interceptor_pop_opline_before_binding() {
    struct zai_interceptor_opline *backup = zai_interceptor_opline_before_binding.prev;
    if (backup) {
        zai_interceptor_opline_before_binding = *backup;
        efree(backup);
        zend_op *opline = (zend_op *)zai_interceptor_opline_before_binding.op;
        zend_op *target_op = &zai_interceptor_post_declare_ops[0];
        *target_op = *opline;
        zval *constant = (zval *)&zai_interceptor_post_declare_ops[2];
        if (opline->op1_type == IS_CONST) {
            // DECLARE_* ops all have two consecutive literals in op1
            ZVAL_COPY_VALUE(&constant[0], RT_CONSTANT(opline, opline->op1));
            ZVAL_COPY_VALUE(&constant[1], RT_CONSTANT(opline, opline->op1) + 1);
            target_op->op1.constant = sizeof(zend_op) * 2;
        }
        if (opline->op2_type == IS_CONST) {
            ZVAL_COPY_VALUE(&constant[2], RT_CONSTANT(opline, opline->op2));
            ZVAL_COPY_VALUE(&constant[3], RT_CONSTANT(opline, opline->op2) + 1);
            target_op->op2.constant = sizeof(zend_op) * 2 + sizeof(zval) * 2;
        }
    } else {
        zai_interceptor_opline_before_binding.op = NULL;
    }
}

static user_opcode_handler_t prev_post_declare_handler;
static int zai_interceptor_post_declare_handler(zend_execute_data *execute_data) {
    if (EX(opline) == &zai_interceptor_post_declare_ops[0] || EX(opline) == &zai_interceptor_post_declare_ops[1]) {
        zend_string *lcname = Z_STR_P(RT_CONSTANT(&zai_interceptor_post_declare_ops[0], zai_interceptor_post_declare_ops[0].op1));
        if (zai_interceptor_post_declare_ops[0].opcode == ZEND_DECLARE_FUNCTION) {
            zend_function *function = zend_hash_find_ptr(CG(function_table), lcname);
            if (function) {
                zai_hook_resolve_function(function, lcname);
            }
        } else {
            zend_class_entry *ce = zend_hash_find_ptr(CG(class_table), lcname);
            if (ce) {
                zai_hook_resolve_class(ce, lcname);
            }
        }
        // preserve offset
        EX(opline) = zai_interceptor_opline_before_binding.op + (EX(opline) - &zai_interceptor_post_declare_ops[0]);
        zai_interceptor_pop_opline_before_binding();
        return ZEND_USER_OPCODE_CONTINUE;
    } else if (prev_post_declare_handler) {
        return prev_post_declare_handler(execute_data);
    } else {
        return ZEND_NOP; // should be unreachable, but don't crash?
    }
}

static user_opcode_handler_t prev_declare_function_handler;
static int zai_interceptor_declare_function_handler(zend_execute_data *execute_data) {
    if (ZEND_DECLARE_FUNCTION == EX(opline)->opcode) {
        zai_interceptor_install_post_declare_op(execute_data);
    }
    return prev_declare_function_handler ? prev_declare_function_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static user_opcode_handler_t prev_declare_class_handler;
static int zai_interceptor_declare_class_handler(zend_execute_data *execute_data) {
    if (ZEND_DECLARE_CLASS == EX(opline)->opcode) {
        zai_interceptor_install_post_declare_op(execute_data);
    }
    return prev_declare_class_handler ? prev_declare_class_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static user_opcode_handler_t prev_declare_class_delayed_handler;
static int zai_interceptor_declare_class_delayed_handler(zend_execute_data *execute_data) {
    if (ZEND_DECLARE_CLASS_DELAYED == EX(opline)->opcode) {
        zai_interceptor_install_post_declare_op(execute_data);
    }
    return prev_declare_class_delayed_handler ? prev_declare_class_delayed_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static user_opcode_handler_t prev_handle_exception_handler;
static int zai_interceptor_handle_exception_handler(zend_execute_data *execute_data) {
    // not everything goes through zend_throw_exception_hook, in particular when zend_rethrow_exception alone is used (e.g. during zend_call_function)
    if (EG(opline_before_exception) == zai_interceptor_post_declare_ops) {
        EG(opline_before_exception) = zai_interceptor_opline_before_binding.op;
        zai_interceptor_pop_opline_before_binding();
    }

    return prev_handle_exception_handler ? prev_handle_exception_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static void (*prev_exception_hook)(zend_object *);
static void zai_interceptor_exception_hook(zend_object *ex) {
    zend_function *func = EG(current_execute_data)->func;
    if (func && ZEND_USER_CODE(func->type) && EG(current_execute_data)->opline == zai_interceptor_post_declare_ops) {
        // called right before setting EG(opline_before_exception), reset to original value to ensure correct throw_op handling
        EG(current_execute_data)->opline = zai_interceptor_opline_before_binding.op;
        zai_interceptor_pop_opline_before_binding();
    }
    if (prev_exception_hook) {
        prev_exception_hook(ex);
    }
}

// startup hook to be after opcache
void zai_interceptor_setup_resolving_startup(void) {
    prev_compile_file = zend_compile_file;
    zend_compile_file = zai_interceptor_compile_file;
    prev_compile_string = zend_compile_string;
    zend_compile_string = zai_interceptor_compile_string;

    zend_internal_function *function = zend_hash_str_find_ptr(CG(function_table), ZEND_STRL("class_alias"));
    prev_class_alias = function->handler;
    function->handler = PHP_FN(zai_interceptor_resolve_after_class_alias);

    prev_declare_function_handler = zend_get_user_opcode_handler(ZEND_DECLARE_FUNCTION);
    zend_set_user_opcode_handler(ZEND_DECLARE_FUNCTION, zai_interceptor_declare_function_handler);
    prev_declare_class_handler = zend_get_user_opcode_handler(ZEND_DECLARE_CLASS);
    zend_set_user_opcode_handler(ZEND_DECLARE_CLASS, zai_interceptor_declare_class_handler);
    prev_declare_class_delayed_handler = zend_get_user_opcode_handler(ZEND_DECLARE_CLASS_DELAYED);
    zend_set_user_opcode_handler(ZEND_DECLARE_CLASS_DELAYED, zai_interceptor_declare_class_delayed_handler);

    prev_post_declare_handler = zend_get_user_opcode_handler(ZAI_INTERCEPTOR_POST_DECLARE_OP);
    zend_set_user_opcode_handler(ZAI_INTERCEPTOR_POST_DECLARE_OP, zai_interceptor_post_declare_handler);

    zend_op *op = &zai_interceptor_post_declare_op;
    op->lineno = 0;
    SET_UNUSED(op->result);
    SET_UNUSED(op->op1);
    SET_UNUSED(op->op2);
    op->opcode = ZAI_INTERCEPTOR_POST_DECLARE_OP;
    ZEND_VM_SET_OPCODE_HANDLER(op);

    prev_handle_exception_handler = zend_get_user_opcode_handler(ZEND_HANDLE_EXCEPTION);
    zend_set_user_opcode_handler(ZEND_HANDLE_EXCEPTION, zai_interceptor_handle_exception_handler);

    prev_exception_hook = zend_throw_exception_hook;
    zend_throw_exception_hook = zai_interceptor_exception_hook;

#ifndef ZTS
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op));
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op)+1);
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op)+2);
#endif
}
