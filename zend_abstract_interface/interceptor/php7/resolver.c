#include <Zend/zend_compile.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_vm.h>
#include "../../hook/hook.h"

static zend_op_array *(*prev_compile_file)(zend_file_handle *file_handle, int type);
static zend_op_array *zai_interceptor_compile_file(zend_file_handle *file_handle, int type) {
    zend_op_array *op_array = prev_compile_file(file_handle, type);
    zai_hook_resolve();
    return op_array;
}

static zend_op_array *(*prev_compile_string)(zval *source_string, char *filename);
static zend_op_array *zai_interceptor_compile_string(zval *source_string, char *filename) {
    zend_op_array *op_array = prev_compile_string(source_string, filename);
    zai_hook_resolve();
    return op_array;
}

static void (*prev_class_alias)(INTERNAL_FUNCTION_PARAMETERS);
PHP_FUNCTION(zai_interceptor_resolve_after_class_alias) {
    prev_class_alias(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    zai_hook_resolve();
}

#define ZAI_INTERCEPTOR_POST_DECLARE_OP 224 // random 8 bit number greater than ZEND_VM_LAST_OPCODE
static zend_op zai_interceptor_post_declare_op;
static __thread zend_op zai_interceptor_post_declare_ops[4];
static __thread const zend_op *zai_interceptor_opline_before_binding;
static void zai_interceptor_install_post_declare_op(zend_execute_data *execute_data) {
    // We replace the current opline *before* it is executed. Thus we need to preserve opline data first:
    //  only the second opline can be our own opcode.
    zend_op *opline = &zai_interceptor_post_declare_ops[0];
    *opline = *EX(opline);
    zai_interceptor_post_declare_ops[1] = zai_interceptor_post_declare_op;
#if PHP_VERSION_ID > 70300 && !ZEND_USE_ABS_CONST_ADDR
    // on PHP 7.3+ literals are opline-relative and thus need to be relocated
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
#endif

    zai_interceptor_opline_before_binding = EX(opline);
    EX(opline) = zai_interceptor_post_declare_ops;
}

static user_opcode_handler_t prev_post_declare_handler;
static int zai_interceptor_post_declare_handler(zend_execute_data *execute_data) {
    if (EX(opline) == &zai_interceptor_post_declare_ops[0] || EX(opline) == &zai_interceptor_post_declare_ops[1]) {
        zai_hook_resolve();
        // preserve offset
        EX(opline) = zai_interceptor_opline_before_binding + (EX(opline) - &zai_interceptor_post_declare_ops[0]);
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

#if PHP_VERSION_ID > 70400
static user_opcode_handler_t prev_declare_class_delayed_handler;
static int zai_interceptor_declare_class_delayed_handler(zend_execute_data *execute_data) {
    if (ZEND_DECLARE_CLASS_DELAYED == EX(opline)->opcode) {
        zai_interceptor_install_post_declare_op(execute_data);
    }
    return prev_declare_class_delayed_handler ? prev_declare_class_delayed_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}
#else
static user_opcode_handler_t prev_declare_inherited_class_handler;
static int zai_interceptor_declare_inherited_class_handler(zend_execute_data *execute_data) {
    if (ZEND_DECLARE_INHERITED_CLASS == EX(opline)->opcode) {
        zai_interceptor_install_post_declare_op(execute_data);
    }
    return prev_declare_inherited_class_handler ? prev_declare_inherited_class_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static user_opcode_handler_t prev_declare_inherited_class_delayed_handler;
static int zai_interceptor_declare_inherited_class_delayed_handler(zend_execute_data *execute_data) {
    if (ZEND_DECLARE_INHERITED_CLASS_DELAYED == EX(opline)->opcode) {
        zai_interceptor_install_post_declare_op(execute_data);
    }
    return prev_declare_inherited_class_delayed_handler ? prev_declare_inherited_class_delayed_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}
#endif

static void (*prev_exception_hook)(zval *);
static void zai_interceptor_exception_hook(zval *ex) {
    if (EG(current_execute_data)->opline == zai_interceptor_post_declare_ops) {
        // called right before setting EG(opline_before_exception), reset to original value to ensure correct throw_op handling
        EG(current_execute_data)->opline = zai_interceptor_opline_before_binding;
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
#if PHP_VERSION_ID > 70400
    prev_declare_class_delayed_handler = zend_get_user_opcode_handler(ZEND_DECLARE_CLASS_DELAYED);
    zend_set_user_opcode_handler(ZEND_DECLARE_CLASS_DELAYED, zai_interceptor_declare_class_delayed_handler);
#else
    prev_declare_inherited_class_handler = zend_get_user_opcode_handler(ZEND_DECLARE_INHERITED_CLASS);
    zend_set_user_opcode_handler(ZEND_DECLARE_INHERITED_CLASS, zai_interceptor_declare_inherited_class_handler);
    prev_declare_inherited_class_delayed_handler = zend_get_user_opcode_handler(ZEND_DECLARE_INHERITED_CLASS_DELAYED);
    zend_set_user_opcode_handler(ZEND_DECLARE_INHERITED_CLASS_DELAYED, zai_interceptor_declare_inherited_class_delayed_handler);
#endif

    prev_post_declare_handler = zend_get_user_opcode_handler(ZAI_INTERCEPTOR_POST_DECLARE_OP);
    zend_set_user_opcode_handler(ZAI_INTERCEPTOR_POST_DECLARE_OP, zai_interceptor_post_declare_handler);

    zend_op *op = &zai_interceptor_post_declare_op;
    op->lineno = 0;
    SET_UNUSED(op->result);
    SET_UNUSED(op->op1);
    SET_UNUSED(op->op2);
    op->opcode = ZAI_INTERCEPTOR_POST_DECLARE_OP;
    ZEND_VM_SET_OPCODE_HANDLER(op);

    prev_exception_hook = zend_throw_exception_hook;
    zend_throw_exception_hook = zai_interceptor_exception_hook;
}