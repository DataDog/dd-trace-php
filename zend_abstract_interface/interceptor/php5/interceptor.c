#include "interceptor.h"
#include "../../hook/hook.h"
#include "../../hook/table.h"
#include <ext/standard/basic_functions.h>
#include <Zend/zend_vm.h>
#if PHP_VERSION_ID >= 50500
#include <Zend/zend_generators.h>
#endif

#if PHP_VERSION_ID < 50500
#define EX_TMP_VAR(ex, n)	   ((temp_variable*)(((char*)((ex)->Ts)) + ((int)(n))))
#define EX_CV_NUM(ex, n)       ((zval***)((ex)->CVs+(n)))
#endif

static ZEND_FUNCTION(pass)
{
}

static const zend_internal_function zend_pass_function = {
        ZEND_INTERNAL_FUNCTION, /* type              */
        NULL,                   /* name              */
        NULL,                   /* scope             */
        0,                      /* fn_flags */
        NULL,                   /* prototype         */
        0,                      /* num_args          */
        0,                      /* required_num_args */
        NULL,                   /* arg_info          */
        ZEND_FN(pass),          /* handler           */
        NULL                    /* module            */
};

typedef struct {
    zai_hook_memory_t hook_data;
    zend_execute_data *execute_data;
} zai_interceptor_frame_memory;

__thread HashTable zai_hook_memory;
// execute_data is 16 byte aligned (except when it isn't, but it doesn't matter as zend_execute_data is big enough
// our goal is to reduce conflicts
static inline bool zai_hook_memory_table_insert(zend_execute_data *index, zai_interceptor_frame_memory *inserting) {
    void *inserted;
    return zai_hook_table_insert_at(&zai_hook_memory, ((zend_ulong)index) >> 4, inserting, sizeof(*inserting), &inserted);
}

static inline bool zai_hook_memory_table_find(zend_execute_data *index, zai_interceptor_frame_memory **found) {
    return zai_hook_table_find(&zai_hook_memory, ((zend_ulong)index) >> 4, (void **)found);
}

static inline bool zai_hook_memory_table_del(zend_execute_data *index) {
    return zend_hash_index_del(&zai_hook_memory, ((zend_ulong)index) >> 4);
}

static void zai_set_ext_nop(zend_op *op TSRMLS_DC) {
    memset(op, 0, sizeof(zend_op));
    op->lineno = CG(zend_lineno);
    SET_UNUSED(op->result);
    SET_UNUSED(op->op1);
    SET_UNUSED(op->op2);
    op->opcode = ZEND_EXT_NOP;
}

void zai_interceptor_op_array_ctor(zend_op_array *op_array) {
    TSRMLS_FETCH();
    // push our own EXT_NOP onto the op_array start
    if (!(CG(compiler_options) & ZEND_COMPILE_EXTENDED_INFO)) {
        if (op_array->last == 0 || op_array->opcodes[0].opcode != ZEND_EXT_NOP) {
            zai_set_ext_nop(&op_array->opcodes[op_array->last++] TSRMLS_CC);
        }
    }
}

static user_opcode_handler_t prev_ext_nop_handler;
static inline int zai_interceptor_ext_nop_handler_no_prev(zend_execute_data *execute_data TSRMLS_DC) {
    HashTable *hooks;
    if (UNEXPECTED(zai_hook_resolved_table_find((zend_ulong)execute_data->op_array->opcodes, &hooks))) {
        zai_interceptor_frame_memory frame_memory, *tmp;
        // do not execute a hook twice
        if (!zai_hook_memory_table_find(execute_data, &tmp)) {
            if (zai_hook_continue(execute_data, &frame_memory.hook_data TSRMLS_CC)) {
                frame_memory.execute_data = execute_data;
                zai_hook_memory_table_insert(execute_data, &frame_memory);
            }
        }
    }

    return ZEND_USER_OPCODE_DISPATCH;
}

static int zai_interceptor_ext_nop_handler(zend_execute_data *execute_data TSRMLS_DC) {
    zai_interceptor_ext_nop_handler_no_prev(execute_data TSRMLS_CC);
    return prev_ext_nop_handler(execute_data TSRMLS_CC);
}

static inline void zai_interceptor_return_impl(zend_execute_data *execute_data TSRMLS_DC) {
    zai_interceptor_frame_memory *frame_memory;
    if (zai_hook_memory_table_find(execute_data, &frame_memory)) {
        zval *retval;
        switch (execute_data->opline->op1_type) {
            case IS_CONST:
                ALLOC_ZVAL(retval);
                MAKE_COPY_ZVAL(&execute_data->opline->op1.zv, retval);
                break;
            case IS_TMP_VAR:
                ALLOC_ZVAL(retval);
                INIT_PZVAL_COPY(retval, &EX_TMP_VAR(execute_data, execute_data->opline->op1.var)->tmp_var);
                zval_copy_ctor(retval);
                break;
            case IS_VAR:
                retval = EX_TMP_VAR(execute_data, execute_data->opline->op1.var)->var.ptr;
                if (ZEND_RETURN == execute_data->opline->opcode) {
                    SEPARATE_ARG_IF_REF(retval);
                } else {
                    Z_ADDREF_P(retval);
                }
                break;
            case IS_CV: {
                zval ***cv = EX_CV_NUM(execute_data, execute_data->opline->op1.var);
                if (*cv != NULL) {
                    retval = **cv;
                    if (ZEND_RETURN == execute_data->opline->opcode) {
                        SEPARATE_ARG_IF_REF(retval);
                    } else {
                        Z_ADDREF_P(retval);
                    }
                    break;
                }
                /* fallthrough */
            }
            default:
                MAKE_STD_ZVAL(retval);
                ZVAL_NULL(retval);
        }

        zai_hook_finish(execute_data, retval, &frame_memory->hook_data TSRMLS_CC);
        zval_ptr_dtor(&retval);
        zai_hook_memory_table_del(execute_data);
    }
}

static user_opcode_handler_t prev_return_handler;
static inline int zai_interceptor_return_handler_no_prev(zend_execute_data *execute_data TSRMLS_DC) {
    if (ZEND_RETURN == execute_data->opline->opcode) {
        zai_interceptor_return_impl(execute_data TSRMLS_CC);
    }
    return ZEND_USER_OPCODE_DISPATCH;
}

static int zai_interceptor_return_handler(zend_execute_data *execute_data TSRMLS_DC) {
    zai_interceptor_return_handler_no_prev(execute_data TSRMLS_CC);
    return prev_return_handler(execute_data TSRMLS_CC);
}

static user_opcode_handler_t prev_return_by_ref_handler;
static int zai_interceptor_return_by_ref_handler(zend_execute_data *execute_data TSRMLS_DC) {
    if (ZEND_RETURN_BY_REF == execute_data->opline->opcode) {
        zai_interceptor_return_impl(execute_data TSRMLS_CC);
    }
    return prev_return_by_ref_handler ? prev_return_by_ref_handler(execute_data TSRMLS_CC) : ZEND_USER_OPCODE_DISPATCH;
}

#if PHP_VERSION_ID >= 50500
static user_opcode_handler_t prev_generator_return_handler;
static int zai_interceptor_generator_return_handler(zend_execute_data *execute_data TSRMLS_DC) {
    if (ZEND_GENERATOR_RETURN == execute_data->opline->opcode) {
        zai_interceptor_frame_memory *frame_memory;
        if (zai_hook_memory_table_find(execute_data, &frame_memory)) {
            zval retval;
            ZVAL_NULL(&retval);
            zai_hook_finish(execute_data, &retval, &frame_memory->hook_data TSRMLS_CC);
            zai_hook_memory_table_del(execute_data);
        }
    }
    return prev_generator_return_handler ? prev_generator_return_handler(execute_data TSRMLS_CC) : ZEND_USER_OPCODE_DISPATCH;
}
#endif

static inline zend_op *zai_interceptor_get_next_catch_block(zend_op_array *op_array, zend_op *opline) {
    if (opline->result.num) {
        return NULL;
    }
    return &op_array->opcodes[opline->extended_value];
}

static inline zend_class_entry *zai_interceptor_get_catching_ce(const zend_op *opline TSRMLS_DC) {
    zend_class_entry *catch_ce = NULL;
    catch_ce = CACHED_PTR(opline->op1.literal->cache_slot);
    if (catch_ce == NULL) {
        catch_ce = zend_fetch_class_by_name(Z_STRVAL_P(opline->op1.zv), Z_STRLEN_P(opline->op1.zv), opline->op1.literal + 1, ZEND_FETCH_CLASS_NO_AUTOLOAD TSRMLS_CC);
    }
    return catch_ce;
}

static bool zai_interceptor_is_catching_frame(zend_op_array *op_array, const zend_op *throw_op TSRMLS_DC) {
    zend_class_entry *ce, *catch_ce;
    zend_try_catch_element *try_catch;
    uint32_t throw_op_num = throw_op - op_array->opcodes;
    int i, current_try_catch_offset = -1;

    // TODO Handle exceptions thrown during function frame leaving to attach them to the right span? Maybe?

    // Find the innermost try/catch block the exception was thrown in
    for (i = 0; i < op_array->last_try_catch; i++) {
        try_catch = &op_array->try_catch_array[i];
        if (try_catch->try_op > throw_op_num) {
            // Exception was thrown before any remaining try/catch blocks
            break;
        }
        if (throw_op_num < try_catch->catch_op
#if PHP_VERSION_ID >= 50500
            || throw_op_num < try_catch->finally_end
#endif
        ) {
            current_try_catch_offset = i;
        }
    }

    while (current_try_catch_offset > -1) {
        try_catch = &op_array->try_catch_array[current_try_catch_offset];
        // Found a catch or finally block
#if PHP_VERSION_ID >= 50500
        if (throw_op_num < try_catch->finally_op) {
            return true;
        }
#endif
        if (throw_op_num < try_catch->catch_op) {
            zend_op *opline = &op_array->opcodes[try_catch->catch_op];
            // Traverse all the catch blocks
            do {
                catch_ce = zai_interceptor_get_catching_ce(opline TSRMLS_CC);
                if (catch_ce != NULL) {
                    ce = Z_OBJCE_P(EG(exception));
                    if (ce == catch_ce || instanceof_function(ce, catch_ce TSRMLS_CC)) {
                        return true;
                    }
                }
                opline = zai_interceptor_get_next_catch_block(op_array, opline);
            } while (opline != NULL);
        }
        current_try_catch_offset--;
    }

    return false;
}

#if PHP_VERSION_ID >= 50500
static user_opcode_handler_t prev_fast_ret_handler;
static int zai_interceptor_fast_ret_handler(zend_execute_data *execute_data TSRMLS_DC) {
    zai_interceptor_frame_memory *frame_memory;
    if (ZEND_FAST_RET == execute_data->opline->opcode && zai_hook_memory_table_find(execute_data, &frame_memory)) {
        // -1 when an exception exists
        if (!execute_data->fast_ret) {
            // The catching frame's span will get closed by the return handler so we leave it open
            if (zai_interceptor_is_catching_frame(execute_data->op_array, execute_data->opline TSRMLS_CC) == false) {
                EG(exception) = execute_data->delayed_exception;
                zai_hook_finish(execute_data, EG(uninitialized_zval_ptr), &frame_memory->hook_data TSRMLS_CC);
                EG(exception) = NULL;
                zai_hook_memory_table_del(execute_data);
            }
        }
    }

    return prev_fast_ret_handler ? prev_fast_ret_handler(execute_data TSRMLS_CC) : ZEND_USER_OPCODE_DISPATCH;
}
#endif

static user_opcode_handler_t prev_handle_exception_handler;
static int zai_interceptor_handle_exception_handler(zend_execute_data *execute_data TSRMLS_DC) {
    zai_interceptor_frame_memory *frame_memory;
    if (ZEND_HANDLE_EXCEPTION == execute_data->opline->opcode && zai_hook_memory_table_find(execute_data, &frame_memory)) {
        // The catching frame's span will get closed by the return handler so we leave it open
        if (zai_interceptor_is_catching_frame(EG(active_op_array), EG(opline_before_exception) TSRMLS_CC) == false) {
            zai_hook_finish(execute_data, EG(uninitialized_zval_ptr), &frame_memory->hook_data TSRMLS_CC);
            zai_hook_memory_table_del(execute_data);
        }
    }

    return prev_handle_exception_handler ? prev_handle_exception_handler(execute_data TSRMLS_CC) : ZEND_USER_OPCODE_DISPATCH;
}

static zend_class_entry zai_interceptor_bailout_ce;
static zend_object_handlers zai_interceptor_bailout_handlers;
static int zai_interceptor_bailout_get_closure(zval *obj, zend_class_entry **ce_ptr, union _zend_function **fptr_ptr, zval **zobj_ptr TSRMLS_DC) {
    (void)obj;
    *fptr_ptr = (zend_function *)&zend_pass_function;
    *ce_ptr = &zai_interceptor_bailout_ce;
    *zobj_ptr = NULL;
    if (CG(unclean_shutdown)) {
        // we do this directly in the get_closure handler instead of a function to avoid an extra pushed stack frame in traces
        HashPosition pos;
        zai_interceptor_frame_memory *frame_memory;

        for (zend_hash_internal_pointer_reset_ex(&zai_hook_memory, &pos);
             zend_hash_get_current_data_ex(&zai_hook_memory, (void **)&frame_memory, &pos) == SUCCESS;
             zend_hash_move_forward_ex(&zai_hook_memory, &pos)) {
            // the individual execute_data contents here may point to bogus (but allocated) memory, but it's just used as key here, hence there's no issue.
            zai_hook_finish(frame_memory->execute_data, EG(uninitialized_zval_ptr), &frame_memory->hook_data TSRMLS_CC);
        }
        zend_hash_clean(&zai_hook_memory);
    }
    return SUCCESS;
}

#if PHP_VERSION_ID < 50500
static void (*prev_execute_internal)(zend_execute_data *execute_data, int return_value_used TSRMLS_DC);
static inline void zai_interceptor_execute_internal(zend_execute_data *execute_data, int return_value_used TSRMLS_DC) {
    HashTable *hooks;
    zend_function *func = execute_data->function_state.function;
    if (UNEXPECTED(zai_hook_resolved_table_find((zend_ulong)func, &hooks))) {
        zai_interceptor_frame_memory frame_memory;
        if (!zai_hook_continue(execute_data, &frame_memory.hook_data TSRMLS_CC)) {
            goto skip;
        }

        frame_memory.execute_data = execute_data;
        zai_hook_memory_table_insert(execute_data, &frame_memory);

        // we do not use try / catch here as to preserve order of hooks, LIFO style, in bailout handler
        prev_execute_internal(execute_data, 1 TSRMLS_CC);

        zval *return_value = EX_TMP_VAR(execute_data, execute_data->opline->result.var)->var.ptr;
        if (EG(exception)) {
            return_value = EG(uninitialized_zval_ptr);
        }
        zai_hook_finish(execute_data, return_value, &frame_memory.hook_data TSRMLS_CC);
        zai_hook_memory_table_del(execute_data);
    } else {
        skip:
        prev_execute_internal(execute_data, return_value_used TSRMLS_CC);
    }
}
#else
static void (*prev_execute_internal)(zend_execute_data *execute_data, zend_fcall_info *fci, int return_value_used TSRMLS_DC);
static inline void zai_interceptor_execute_internal(zend_execute_data *execute_data, zend_fcall_info *fci, int return_value_used TSRMLS_DC) {
    HashTable *hooks;
    zend_function *func = execute_data->function_state.function;
    if (UNEXPECTED(zai_hook_resolved_table_find((zend_ulong)func, &hooks))) {
        zai_interceptor_frame_memory frame_memory;
        if (!zai_hook_continue(execute_data, &frame_memory.hook_data TSRMLS_CC)) {
            goto skip;
        }

        frame_memory.execute_data = execute_data;
        zai_hook_memory_table_insert(execute_data, &frame_memory);

        // we do not use try / catch here as to preserve order of hooks, LIFO style, in bailout handler
        prev_execute_internal(execute_data, fci, 1 TSRMLS_CC);

        zval *return_value = fci ? *fci->retval_ptr_ptr : EX_TMP_VAR(execute_data, execute_data->opline->result.var)->var.ptr;
        if (EG(exception)) {
            return_value = EG(uninitialized_zval_ptr);
        }
        zai_hook_finish(execute_data, return_value, &frame_memory.hook_data TSRMLS_CC);
        zai_hook_memory_table_del(execute_data);
    } else {
        skip:
        prev_execute_internal(execute_data, fci, return_value_used TSRMLS_CC);
    }
}
#endif

#if PHP_VERSION_ID >= 50500
static zend_objects_store_dtor_t zai_interceptor_generator_dtor_obj;
static void zai_interceptor_generator_dtor_wrapper(void *object, zend_object_handle handle TSRMLS_DC) {
    zend_generator *generator = (zend_generator *)object;
    zend_execute_data *execute_data = generator->execute_data;

    zai_interceptor_generator_dtor_obj(object, handle TSRMLS_CC);

    zai_interceptor_frame_memory *frame_memory;
    if (execute_data && zai_hook_memory_table_find(execute_data, &frame_memory)) {
        zval *retval;
        MAKE_STD_ZVAL(retval)
        ZVAL_NULL(retval);
        zai_hook_finish(execute_data, retval, &frame_memory->hook_data TSRMLS_CC);
        zval_ptr_dtor(&retval);
        zai_hook_memory_table_del(execute_data);
    }
}

static zend_object_value (*generator_create_prev)(zend_class_entry *class_type TSRMLS_DC);
static zend_object_value zai_interceptor_generator_create(zend_class_entry *class_type TSRMLS_DC) {
    zend_object_value obj = generator_create_prev(class_type TSRMLS_CC);
    size_t execute_data_size = ZEND_MM_ALIGNED_SIZE(sizeof(zend_execute_data));
    int args_count = zend_vm_stack_get_args_count_ex(EG(current_execute_data));
    size_t args_size = ZEND_MM_ALIGNED_SIZE(sizeof(zval*)) * (args_count + 1);
    size_t Ts_size = ZEND_MM_ALIGNED_SIZE(sizeof(temp_variable)) * EG(active_op_array)->T;

    zend_execute_data *execute_data = (zend_execute_data*)((char*)ZEND_VM_STACK_ELEMETS(EG(argument_stack)) + args_size + execute_data_size + Ts_size);

    zai_interceptor_frame_memory frame_memory;
    if (zai_hook_continue(execute_data, &frame_memory.hook_data TSRMLS_CC)) {
        frame_memory.execute_data = execute_data;
        zai_hook_memory_table_insert(execute_data, &frame_memory);
    }

    struct _store_object *object = &EG(objects_store).object_buckets[obj.handle].bucket.obj;
    zai_interceptor_generator_dtor_obj = object->dtor;
    object->dtor = zai_interceptor_generator_dtor_wrapper;

    return obj;
}
#endif

void zai_interceptor_setup_resolving_startup(TSRMLS_D);
void zai_interceptor_startup(zend_module_entry *module_entry) {
    TSRMLS_FETCH();

    prev_execute_internal = zend_execute_internal ?: execute_internal;
    zend_execute_internal = zai_interceptor_execute_internal;

    // init
    prev_ext_nop_handler = zend_get_user_opcode_handler(ZEND_EXT_NOP);
    zend_set_user_opcode_handler(ZEND_EXT_NOP, prev_ext_nop_handler ? zai_interceptor_ext_nop_handler : zai_interceptor_ext_nop_handler_no_prev);

    // end
    prev_return_handler = zend_get_user_opcode_handler(ZEND_RETURN);
    user_opcode_handler_t return_handler = prev_return_handler ? zai_interceptor_return_handler : zai_interceptor_return_handler_no_prev;
    zend_set_user_opcode_handler(ZEND_RETURN, return_handler);
    prev_return_by_ref_handler = zend_get_user_opcode_handler(ZEND_RETURN_BY_REF);
    zend_set_user_opcode_handler(ZEND_RETURN_BY_REF, zai_interceptor_return_by_ref_handler);
    prev_handle_exception_handler = zend_get_user_opcode_handler(ZEND_HANDLE_EXCEPTION);
    zend_set_user_opcode_handler(ZEND_HANDLE_EXCEPTION, zai_interceptor_handle_exception_handler);
#if PHP_VERSION_ID >= 50500
    prev_generator_return_handler = zend_get_user_opcode_handler(ZEND_GENERATOR_RETURN);
    zend_set_user_opcode_handler(ZEND_GENERATOR_RETURN, zai_interceptor_generator_return_handler);
    prev_fast_ret_handler = zend_get_user_opcode_handler(ZEND_FAST_RET);
    zend_set_user_opcode_handler(ZEND_FAST_RET, zai_interceptor_fast_ret_handler);
#endif

#ifndef ZTS
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op));
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op)+1);
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op)+2);
#endif

#if PHP_VERSION_ID >= 50500
    generator_create_prev = zend_ce_generator->create_object;
    zend_ce_generator->create_object = zai_interceptor_generator_create;
#endif

    INIT_NS_CLASS_ENTRY(zai_interceptor_bailout_ce, "Zend Abstract Interface", "BailoutHandler", NULL);
    zai_interceptor_bailout_ce.type = ZEND_INTERNAL_CLASS;
    zend_initialize_class_data(&zai_interceptor_bailout_ce, false TSRMLS_CC);
    zai_interceptor_bailout_ce.info.internal.module = module_entry;
    memcpy(&zai_interceptor_bailout_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    zai_interceptor_bailout_handlers.get_closure = zai_interceptor_bailout_get_closure;

    zai_interceptor_setup_resolving_startup(TSRMLS_C);
}

void zai_interceptor_rinit(TSRMLS_D) {
    // install bailout handler - shutdown functions are the first thing we can reliably hook into in case of bailout
    php_shutdown_function_entry shutdown_function = { .arguments = emalloc(sizeof(zval *)), .arg_count = 1 };
    MAKE_STD_ZVAL(*shutdown_function.arguments);
    object_init_ex(*shutdown_function.arguments, &zai_interceptor_bailout_ce);
    Z_OBJ_HT_PP(shutdown_function.arguments) = &zai_interceptor_bailout_handlers;
    register_user_shutdown_function(ZEND_STRL("_dd_bailout_handler"), &shutdown_function TSRMLS_CC);

    zend_hash_init(&zai_hook_memory, 8, NULL, NULL, 0);
}

void zai_interceptor_rshutdown() {
    zend_hash_destroy(&zai_hook_memory);
}
