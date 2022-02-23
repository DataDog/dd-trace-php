#include <Zend/zend_compile.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_vm.h>
#include "../../hook/hook.h"

static zend_op_array *(*prev_compile_file)(zend_file_handle *file_handle, int type TSRMLS_DC);
static zend_op_array *zai_interceptor_compile_file(zend_file_handle *file_handle, int type TSRMLS_DC) {
    zend_op_array *op_array = prev_compile_file(file_handle, type TSRMLS_CC);
    zai_hook_resolve(TSRMLS_C);
    return op_array;
}

static zend_op_array *(*prev_compile_string)(zval *source_string, char *filename TSRMLS_DC);
static zend_op_array *zai_interceptor_compile_string(zval *source_string, char *filename TSRMLS_DC) {
    zend_op_array *op_array = prev_compile_string(source_string, filename TSRMLS_CC);
    zai_hook_resolve(TSRMLS_C);
    return op_array;
}

static void (*prev_class_alias)(INTERNAL_FUNCTION_PARAMETERS);
PHP_FUNCTION(zai_interceptor_resolve_after_class_alias) {
    prev_class_alias(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    zai_hook_resolve(TSRMLS_C);
}

#define ZAI_INTERCEPTOR_POST_DECLARE_OP 224 // random 8 bit number greater than ZEND_VM_LAST_OPCODE
static zend_op zai_interceptor_post_declare_op;
static __thread zend_op zai_interceptor_post_declare_ops[4];
static __thread zend_op *zai_interceptor_opline_before_binding;
static void zai_interceptor_install_post_declare_op(zend_execute_data *execute_data TSRMLS_DC) {
    // We replace the current opline *before* it is executed. Thus we need to preserve opline data first:
    //  only the second opline can be our own opcode.
    zend_op *opline = &zai_interceptor_post_declare_ops[0];
    *opline = *execute_data->opline;
    zai_interceptor_post_declare_ops[1] = zai_interceptor_post_declare_op;
    zai_interceptor_opline_before_binding = execute_data->opline;
    execute_data->opline = zai_interceptor_post_declare_ops;
}

static user_opcode_handler_t prev_post_declare_handler;
static int zai_interceptor_post_declare_handler(zend_execute_data *execute_data TSRMLS_DC) {
    if (execute_data->opline == &zai_interceptor_post_declare_ops[0] || execute_data->opline == &zai_interceptor_post_declare_ops[1]) {
        zai_hook_resolve(TSRMLS_C);
        // preserve offset
        execute_data->opline = zai_interceptor_opline_before_binding + (execute_data->opline - &zai_interceptor_post_declare_ops[0]);
        return ZEND_USER_OPCODE_CONTINUE;
    } else if (prev_post_declare_handler) {
        return prev_post_declare_handler(execute_data TSRMLS_CC);
    } else {
        return ZEND_NOP; // should be unreachable, but don't crash?
    }
}

static user_opcode_handler_t prev_declare_function_handler;
static int zai_interceptor_declare_function_handler(zend_execute_data *execute_data TSRMLS_DC) {
    if (ZEND_DECLARE_FUNCTION == execute_data->opline->opcode) {
        zai_interceptor_install_post_declare_op(execute_data TSRMLS_CC);
    }
    return prev_declare_function_handler ? prev_declare_function_handler(execute_data TSRMLS_CC) : ZEND_USER_OPCODE_DISPATCH;
}

static user_opcode_handler_t prev_declare_class_handler;
static int zai_interceptor_declare_class_handler(zend_execute_data *execute_data TSRMLS_DC) {
    if (ZEND_DECLARE_CLASS == execute_data->opline->opcode) {
        zai_interceptor_install_post_declare_op(execute_data TSRMLS_CC);
    }
    return prev_declare_class_handler ? prev_declare_class_handler(execute_data TSRMLS_CC) : ZEND_USER_OPCODE_DISPATCH;
}

static user_opcode_handler_t prev_declare_inherited_class_handler;
static int zai_interceptor_declare_inherited_class_handler(zend_execute_data *execute_data TSRMLS_DC) {
    if (ZEND_DECLARE_INHERITED_CLASS == execute_data->opline->opcode) {
        zai_interceptor_install_post_declare_op(execute_data TSRMLS_CC);
    }
    return prev_declare_inherited_class_handler ? prev_declare_inherited_class_handler(execute_data TSRMLS_CC) : ZEND_USER_OPCODE_DISPATCH;
}

static user_opcode_handler_t prev_declare_inherited_class_delayed_handler;
static int zai_interceptor_declare_inherited_class_delayed_handler(zend_execute_data *execute_data TSRMLS_DC) {
    if (ZEND_DECLARE_INHERITED_CLASS_DELAYED == execute_data->opline->opcode) {
        zai_interceptor_install_post_declare_op(execute_data TSRMLS_CC);
    }
    return prev_declare_inherited_class_delayed_handler ? prev_declare_inherited_class_delayed_handler(execute_data TSRMLS_CC) : ZEND_USER_OPCODE_DISPATCH;
}

static void (*prev_exception_hook)(zval * TSRMLS_DC);
static void zai_interceptor_exception_hook(zval *ex TSRMLS_DC) {
    if (EG(current_execute_data)->opline == zai_interceptor_post_declare_ops) {
        // called right before setting EG(opline_before_exception), reset to original value to ensure correct throw_op handling
        EG(current_execute_data)->opline = zai_interceptor_opline_before_binding;
    }
    if (prev_exception_hook) {
        prev_exception_hook(ex TSRMLS_CC);
    }
}

// startup hook to be after opcache
void zai_interceptor_setup_resolving_startup(TSRMLS_D) {
    prev_compile_file = zend_compile_file;
    zend_compile_file = zai_interceptor_compile_file;
    prev_compile_string = zend_compile_string;
    zend_compile_string = zai_interceptor_compile_string;

    zend_internal_function *function;
    zend_hash_find(CG(function_table), ZEND_STRS("class_alias"), (void **)&function);
    prev_class_alias = function->handler;
    function->handler = PHP_FN(zai_interceptor_resolve_after_class_alias);

    prev_declare_function_handler = zend_get_user_opcode_handler(ZEND_DECLARE_FUNCTION);
    zend_set_user_opcode_handler(ZEND_DECLARE_FUNCTION, zai_interceptor_declare_function_handler);
    prev_declare_class_handler = zend_get_user_opcode_handler(ZEND_DECLARE_CLASS);
    zend_set_user_opcode_handler(ZEND_DECLARE_CLASS, zai_interceptor_declare_class_handler);
    prev_declare_inherited_class_handler = zend_get_user_opcode_handler(ZEND_DECLARE_INHERITED_CLASS);
    zend_set_user_opcode_handler(ZEND_DECLARE_INHERITED_CLASS, zai_interceptor_declare_inherited_class_handler);
    prev_declare_inherited_class_delayed_handler = zend_get_user_opcode_handler(ZEND_DECLARE_INHERITED_CLASS_DELAYED);
    zend_set_user_opcode_handler(ZEND_DECLARE_INHERITED_CLASS_DELAYED, zai_interceptor_declare_inherited_class_delayed_handler);

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