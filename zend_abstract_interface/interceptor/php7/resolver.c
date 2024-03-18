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

#if PHP_VERSION_ID >= 70200
// Work around https://github.com/php/php-src/issues/11222
// This is not a pretty workaround, but we don't have any better possibilities...
static void zai_resolver_force_space(HashTable *ht) {
    // Assuming files don't declare more than a thousand functions
    const int reserved = 1000;

    while (ht->nTableSize < reserved + ht->nNumOfElements) {
        // A rehash happens when all elements are used. The rehash also resets the nNumUsed.
        // However, all ht entries within nNumUsed are required to have a valid value.
        memset(ht->arData + ht->nNumUsed, IS_UNDEF, (ht->nTableSize - ht->nNumUsed) * sizeof(Bucket));
        ht->nNumUsed = ht->nTableSize;
        ht->nNumOfElements += reserved;
        dtor_func_t dtor = ht->pDestructor;
        ht->pDestructor = NULL;
        zend_hash_index_add_ptr(ht, 0, NULL);
        zend_hash_index_del(ht, 0);
        ht->pDestructor = dtor;
        ht->nNumOfElements -= reserved;
    }
}
#endif

static zend_op_array *(*prev_compile_file)(zend_file_handle *file_handle, int type);
static zend_op_array *zai_interceptor_compile_file(zend_file_handle *file_handle, int type) {
#if PHP_VERSION_ID >= 70200
    zai_resolver_force_space(CG(class_table));
    zai_resolver_force_space(CG(function_table));
#endif

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

    if (op_array) {
        zai_hook_resolve_file(op_array);
    }

    return op_array;
}

static zend_op_array *(*prev_compile_string)(zval *source_string, char *filename);
static zend_op_array *zai_interceptor_compile_string(zval *source_string, char *filename) {
    HashPosition classpos, funcpos;
    zend_hash_internal_pointer_end_ex(CG(class_table), &classpos);
    zend_hash_internal_pointer_end_ex(CG(function_table), &funcpos);

    zend_op_array *op_array = prev_compile_string(source_string, filename);

    zai_interceptor_add_new_entries(classpos, funcpos);

    return op_array;
}

#if PHP_VERSION_ID < 70200
typedef void (*zif_handler)(INTERNAL_FUNCTION_PARAMETERS);
#endif

static zif_handler prev_class_alias;
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

#define ZAI_INTERCEPTOR_POST_DECLARE_OP 224 // random 8 bit number greater than ZEND_VM_LAST_OPCODE
static zend_op zai_interceptor_post_declare_op;
ZEND_TLS zend_op zai_interceptor_post_declare_ops[4];
struct zai_interceptor_opline { const zend_op *op; const zend_execute_data *execute_data; struct zai_interceptor_opline *prev; };
ZEND_TLS struct zai_interceptor_opline zai_interceptor_opline_before_binding = {0};
static void zai_interceptor_install_post_declare_op(zend_execute_data *execute_data) {
    // We replace the current opline *before* it is executed. Thus we need to preserve opline data first:
    //  only the second opline can be our own opcode.
    zend_op *opline = &zai_interceptor_post_declare_ops[0];
    *opline = *EX(opline);
    zai_interceptor_post_declare_ops[1] = zai_interceptor_post_declare_op;
#if PHP_VERSION_ID >= 70300 && !ZEND_USE_ABS_CONST_ADDR
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

    if (zai_interceptor_opline_before_binding.op) {
        struct zai_interceptor_opline *backup = ecalloc(1, sizeof(*zai_interceptor_opline_before_binding.prev));
        *backup = zai_interceptor_opline_before_binding;
        zai_interceptor_opline_before_binding.prev = backup;
    }
    zai_interceptor_opline_before_binding.op = EX(opline);
    zai_interceptor_opline_before_binding.execute_data = execute_data;
    EX(opline) = zai_interceptor_post_declare_ops;
}

static void zai_interceptor_pop_opline_before_binding(zend_execute_data *execute_data) {
    // Normally the zai_interceptor_opline_before_binding stack should be in sync with the actual executing stack, but it might not after bailouts
    if (execute_data) {
        if (zai_interceptor_opline_before_binding.execute_data == execute_data) {
            return;
        }

        while (zai_interceptor_opline_before_binding.prev && zai_interceptor_opline_before_binding.prev->execute_data != execute_data) {
            struct zai_interceptor_opline *backup = zai_interceptor_opline_before_binding.prev;
            zai_interceptor_opline_before_binding = *backup;
            efree(backup);
        }
    }

    struct zai_interceptor_opline *backup = zai_interceptor_opline_before_binding.prev;
    if (backup) {
        zai_interceptor_opline_before_binding = *backup;
        efree(backup);
        zend_op *opline = (zend_op *)zai_interceptor_opline_before_binding.op;
        zend_op *target_op = &zai_interceptor_post_declare_ops[0];
        *target_op = *opline;
#if PHP_VERSION_ID >= 70300 && !ZEND_USE_ABS_CONST_ADDR
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
#endif
    } else {
        zai_interceptor_opline_before_binding.op = NULL;
    }
}

static user_opcode_handler_t prev_post_declare_handler;
static int zai_interceptor_post_declare_handler(zend_execute_data *execute_data) {
    if (EX(opline) == &zai_interceptor_post_declare_ops[0] || EX(opline) == &zai_interceptor_post_declare_ops[1]) {
#if PHP_VERSION_ID < 70400
        if (zai_interceptor_post_declare_ops[0].opcode == ZEND_BIND_TRAITS || zai_interceptor_post_declare_ops[0].opcode == ZEND_ADD_INTERFACE) {
            zend_class_entry *ce = Z_CE_P(EX_VAR(zai_interceptor_post_declare_ops[0].op1.var));
            zend_string *lcname = zend_string_tolower(ce->name);
            zai_hook_resolve_class(ce, lcname);
            zend_string_release(lcname);
        } else
#endif
        {
#if PHP_VERSION_ID >= 70300
            zend_string *lcname = Z_STR_P(RT_CONSTANT(&zai_interceptor_post_declare_ops[0], zai_interceptor_post_declare_ops[0].op1));
#elif PHP_VERSION_ID >= 70100
            zend_string *lcname = Z_STR_P(EX_CONSTANT(zai_interceptor_post_declare_ops[0].op1));
#else
            zend_string *lcname = Z_STR_P(EX_CONSTANT(zai_interceptor_post_declare_ops[0].op2));
#endif
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
        }
        // preserve offset
        zai_interceptor_pop_opline_before_binding(execute_data);
        EX(opline) = zai_interceptor_opline_before_binding.op + (EX(opline) - &zai_interceptor_post_declare_ops[0]);
        zai_interceptor_pop_opline_before_binding(NULL);
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
#if PHP_VERSION_ID < 70400
        if (EX(opline)[1].opcode != ZEND_BIND_TRAITS && EX(opline)[1].opcode != ZEND_ADD_INTERFACE)
#endif
        {
            zai_interceptor_install_post_declare_op(execute_data);
        }
    }
    return prev_declare_class_handler ? prev_declare_class_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

#if PHP_VERSION_ID >= 70400
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
    if (ZEND_DECLARE_INHERITED_CLASS == EX(opline)->opcode && EX(opline)[1].opcode != ZEND_BIND_TRAITS && EX(opline)[1].opcode != ZEND_ADD_INTERFACE) {
        zai_interceptor_install_post_declare_op(execute_data);
    }
    return prev_declare_inherited_class_handler ? prev_declare_inherited_class_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static user_opcode_handler_t prev_declare_inherited_class_delayed_handler;
static int zai_interceptor_declare_inherited_class_delayed_handler(zend_execute_data *execute_data) {
    if (ZEND_DECLARE_INHERITED_CLASS_DELAYED == EX(opline)->opcode && EX(opline)[1].opcode != ZEND_BIND_TRAITS && EX(opline)[1].opcode != ZEND_ADD_INTERFACE) {
        zai_interceptor_install_post_declare_op(execute_data);
    }
    return prev_declare_inherited_class_delayed_handler ? prev_declare_inherited_class_delayed_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static user_opcode_handler_t prev_bind_traits_handler;
static int zai_interceptor_bind_traits_handler(zend_execute_data *execute_data) {
    if (ZEND_BIND_TRAITS == EX(opline)->opcode && EX(opline)[1].opcode != ZEND_ADD_INTERFACE) {
        zai_interceptor_install_post_declare_op(execute_data);
    }
    return prev_bind_traits_handler ? prev_bind_traits_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static user_opcode_handler_t prev_add_interface_handler;
static int zai_interceptor_add_interface_handler(zend_execute_data *execute_data) {
    if (ZEND_ADD_INTERFACE == EX(opline)->opcode && EX(opline)[1].opcode != ZEND_ADD_INTERFACE) {
        zai_interceptor_install_post_declare_op(execute_data);
    }
    return prev_add_interface_handler ? prev_add_interface_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}
#endif

void zai_interceptor_check_for_opline_before_exception(zend_execute_data *execute_data) {
    if (EG(opline_before_exception) == zai_interceptor_post_declare_ops) {
        zai_interceptor_pop_opline_before_binding(execute_data);
        EG(opline_before_exception) = zai_interceptor_opline_before_binding.op;
        zai_interceptor_pop_opline_before_binding(NULL);
    }
}

static void (*prev_exception_hook)(zval *);
static void zai_interceptor_exception_hook(zval *ex) {
    zend_function *func = EG(current_execute_data)->func;
    if (func && ZEND_USER_CODE(func->type) && EG(current_execute_data)->opline == zai_interceptor_post_declare_ops) {
        // called right before setting EG(opline_before_exception), reset to original value to ensure correct throw_op handling
        zai_interceptor_pop_opline_before_binding(EG(current_execute_data));
        EG(current_execute_data)->opline = zai_interceptor_opline_before_binding.op;
        zai_interceptor_pop_opline_before_binding(NULL);
    }
    if (prev_exception_hook) {
        prev_exception_hook(ex);
    }
}

void zai_interceptor_reset_resolver(void) {
    // reset in case a prior request had a bailout
    zai_interceptor_opline_before_binding = (struct zai_interceptor_opline){0};
}

// post startup hook to be after opcache
void zai_interceptor_setup_resolving_post_startup(void) {
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
    prev_bind_traits_handler = zend_get_user_opcode_handler(ZEND_BIND_TRAITS);
    zend_set_user_opcode_handler(ZEND_BIND_TRAITS, zai_interceptor_bind_traits_handler);
    prev_add_interface_handler = zend_get_user_opcode_handler(ZEND_ADD_INTERFACE);
    zend_set_user_opcode_handler(ZEND_ADD_INTERFACE, zai_interceptor_add_interface_handler);
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

void zai_interceptor_shutdown_resolving(void) {
    zend_set_user_opcode_handler(ZEND_DECLARE_FUNCTION, NULL);
    zend_set_user_opcode_handler(ZEND_DECLARE_CLASS, NULL);
#if PHP_VERSION_ID > 70400
    zend_set_user_opcode_handler(ZEND_DECLARE_CLASS_DELAYED, NULL);
#else
    zend_set_user_opcode_handler(ZEND_DECLARE_INHERITED_CLASS, NULL);
    zend_set_user_opcode_handler(ZEND_DECLARE_INHERITED_CLASS_DELAYED, NULL);
    zend_set_user_opcode_handler(ZEND_BIND_TRAITS, NULL);
    zend_set_user_opcode_handler(ZEND_ADD_INTERFACE, NULL);
#endif
    zend_set_user_opcode_handler(ZAI_INTERCEPTOR_POST_DECLARE_OP, NULL);
}
