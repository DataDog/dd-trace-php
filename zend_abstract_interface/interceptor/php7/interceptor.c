#include "interceptor.h"
#include "../../hook/hook.h"
#include "../../hook/table.h"
#include "zend_vm.h"
#include <ext/standard/basic_functions.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_generators.h>
#ifndef _WIN32
#include <pthread.h>
#else
#include <components/pthread_polyfill.h>
#endif

#if PHP_VERSION_ID < 70100
static ZEND_FUNCTION(pass)
{
    (void)execute_data, (void)return_value;
}

// HACK: define a name for XDebug compatibility..., a fully functional zend_string for "{zend_pass}"
static const struct __attribute__((packed, aligned(_Alignof(zend_string)))) {
    zend_string str;
    char value[];
} zend_pass_function_name = {
        .str.gc.refcount = 2,
        .str.gc.u.v.type = IS_STRING,
        .str.gc.u.v.flags = IS_STR_INTERNED,
        .str.len = 11,
        .str.h = 0xc0ff0fab5cdbc6c4,
        .str.val = { '{' }, .value = {'z', 'e', 'n', 'd', '_', 'p', 'a', 's', 's', '}'}
};

static const zend_internal_function zend_pass_function = {
        ZEND_INTERNAL_FUNCTION, /* type              */
        {0, 0, 0},              /* arg_flags         */
        0,                      /* fn_flags          */
        (zend_string *)&zend_pass_function_name.str,     /* name              */
        NULL,                   /* scope             */
        NULL,                   /* prototype         */
        0,                      /* num_args          */
        0,                      /* required_num_args */
        NULL,                   /* arg_info          */
        ZEND_FN(pass),          /* handler           */
        NULL,                   /* module            */
        {0}                     /* reserved          */
};

void (*zai_interrupt_function)(zend_execute_data *execute_data) = NULL;
static bool _zai_default_vm_interrupt = false;
TSRM_TLS bool *zai_vm_interrupt = &_zai_default_vm_interrupt;
#endif

typedef struct {
    zai_hook_memory_t hook_data;
    zend_execute_data *execute_data;
    bool implicit;
} zai_interceptor_frame_memory;

typedef struct {
    zai_interceptor_frame_memory frame;
    const zend_op *return_op;
    zend_op resumption_ops[3];
    bool resumed;
    uint32_t temporary;
} zai_interceptor_generator_frame_memory;

ZEND_TLS HashTable zai_hook_memory;
// execute_data is 16 byte aligned (except when it isn't, but it doesn't matter as zend_execute_data is big enough
// our goal is to reduce conflicts
static inline void zai_hook_memory_table_insert(zend_execute_data *index, zai_interceptor_frame_memory *inserting) {
    zend_hash_index_update_mem(&zai_hook_memory, ((zend_ulong)index) >> 4, inserting, sizeof(*inserting));
}

static inline void *zai_hook_memory_table_insert_generator(zend_execute_data *index, zai_interceptor_generator_frame_memory *inserting) {
    return zend_hash_index_update_mem(&zai_hook_memory, ((zend_ulong)index) >> 4, inserting, sizeof(*inserting));
}

static inline bool zai_hook_memory_table_find(zend_execute_data *index, zai_interceptor_frame_memory **found) {
    return zai_hook_table_find(&zai_hook_memory, ((zend_ulong)index) >> 4, (void **)found);
}

static inline bool zai_hook_memory_table_del(zend_execute_data *index) {
    return zend_hash_index_del(&zai_hook_memory, ((zend_ulong)index) >> 4);
}

#define ZAI_INTERCEPTOR_CUSTOM_EXT 0xda8ad065

static void zai_set_ext_nop(zend_op *op) {
    memset(op, 0, sizeof(zend_op));
    op->lineno = CG(zend_lineno);
    SET_UNUSED(op->result);
    SET_UNUSED(op->op1);
    SET_UNUSED(op->op2);
    op->opcode = ZEND_EXT_NOP;
    op->extended_value = ZAI_INTERCEPTOR_CUSTOM_EXT;
}

void zai_interceptor_op_array_ctor(zend_op_array *op_array) {
    // push our own EXT_NOP onto the op_array start
    zend_op *op = &op_array->opcodes[op_array->last++];
    zai_set_ext_nop(op);
    // EXT_STMT is skipped by compiler when determining "very first" instructions
    op->opcode = ZEND_EXT_STMT;

    // For reasons this is not set on eval()'ed code, leading to pass_two handler not being called
    CG(compiler_options) |= ZEND_COMPILE_HANDLE_OP_ARRAY;
}

static inline bool zai_is_func_recv_opcode(zend_uchar opcode) {
    return opcode == ZEND_RECV || opcode == ZEND_RECV_INIT || opcode == ZEND_RECV_VARIADIC;
}

// Replace EXT_NOP by EXT_STMT, then move it after RECV ops if possible
void zai_interceptor_op_array_pass_two(zend_op_array *op_array) {
    zend_op *opcodes = op_array->opcodes;
    for (zend_op *cur = opcodes, *last = cur + op_array->last; cur < last; ++cur) {
        if (cur->opcode == ZEND_EXT_STMT && cur->extended_value == ZAI_INTERCEPTOR_CUSTOM_EXT) {
            cur->opcode = ZEND_EXT_NOP;
            break;
        }
    }
    // technically not necessary, but we do it as to not hinder the default optimization of skipping the first RECV ops
    uint32_t nop_i = 0;
    while (op_array->last > nop_i && (opcodes[nop_i].opcode != ZEND_EXT_NOP || opcodes[nop_i].extended_value != ZAI_INTERCEPTOR_CUSTOM_EXT)) {
        ++nop_i;
    }
    if (op_array->last > nop_i && opcodes[nop_i].opcode == ZEND_EXT_NOP && opcodes[nop_i].extended_value == ZAI_INTERCEPTOR_CUSTOM_EXT) {
        uint32_t i = nop_i + 1;
        while (zai_is_func_recv_opcode(opcodes[i].opcode)) {
            ++i;
        }
        if (--i > nop_i) {
            memmove(&opcodes[nop_i], &opcodes[nop_i + 1], (i - nop_i) * sizeof(zend_op));
            zai_set_ext_nop(&opcodes[i]);
        }

        // For generators we need our own temporary to store a constant array which is converted to an iterator
        bool need_temporary = op_array->fn_flags & ZEND_ACC_GENERATOR;
        if (!need_temporary) {
            for (uint32_t op = i; op < op_array->last; ++op) {
                // For RETURN opcodes, we may need a temporary to replace the return values
                if ((opcodes[op].opcode == ZEND_RETURN || opcodes[op].opcode == ZEND_RETURN_BY_REF)) {
                    if (opcodes[op].op1_type == IS_CV || opcodes[op].op1_type == IS_CONST) {
                        need_temporary = true;
                        break;
                    }
                }
            }
        }

        if (need_temporary) {
            // For Optimizer to allocate a temporary for us, the temporary must exist
            // To not interfere with live range calculation, the temporary must be defined as a result
            opcodes[i].result_type = IS_TMP_VAR;
            opcodes[i].result.var = op_array->T++;
        } else if (CG(compiler_options) & ZEND_COMPILE_EXTENDED_INFO) {
            // We don't need it, Optimizer, feel free to optimize it away
            opcodes[i].opcode = ZEND_NOP;
        }
    }
}

uint32_t zai_interceptor_find_temporary(zend_op_array *op_array) {
    for (zend_op *op = op_array->opcodes, *end = op + op_array->last; op < end; ++op) {
        if (op->opcode == ZEND_EXT_NOP && op->extended_value == ZAI_INTERCEPTOR_CUSTOM_EXT) {
            return op->result.var;
        }
    }
    return -1;
}

static user_opcode_handler_t prev_ext_nop_handler;
static inline int zai_interceptor_ext_nop_handler_no_prev(zend_execute_data *execute_data) {
#if PHP_VERSION_ID < 70100
    if (UNEXPECTED(*zai_vm_interrupt) && zai_interrupt_function) {
        zai_interrupt_function(execute_data);
    }
#endif
    zend_op_array *op_array = &execute_data->func->op_array;
    if (UNEXPECTED(zai_hook_installed_user(op_array))) {
        zai_interceptor_frame_memory frame_memory, *tmp;
        // do not execute a hook twice, skip unused generators
        if (!zai_hook_memory_table_find(execute_data, &tmp) && ((op_array->fn_flags & ZEND_ACC_GENERATOR) == 0 || EX(return_value))) {
            if (zai_hook_continue(execute_data, &frame_memory.hook_data) == ZAI_HOOK_CONTINUED) {
                frame_memory.execute_data = execute_data;
                frame_memory.implicit = false;
                zai_hook_memory_table_insert(execute_data, &frame_memory);

                if (&execute_data->func->op_array != op_array) {
                    // the code was changed, so instead of executing the original handler of
                    // opline->opcode (gotten via zend_vm_get_opcode_handler_func),
                    // we return ZEND_USER_OPCODE_CONTINUE so that user opcode handler
                    // of the new execute_data->opline is executed
                    return ZEND_USER_OPCODE_CONTINUE;
                }
            }
        }
    }

    return ZEND_USER_OPCODE_DISPATCH;
}

static int zai_interceptor_ext_nop_handler(zend_execute_data *execute_data) {
    int our_ret = zai_interceptor_ext_nop_handler_no_prev(execute_data);
    int their_ret = prev_ext_nop_handler(execute_data);
    zend_op_array *prev_op_array = &execute_data->func->op_array;
    if (their_ret == ZEND_USER_OPCODE_DISPATCH && our_ret == ZEND_USER_OPCODE_CONTINUE && &execute_data->func->op_array == prev_op_array) {
        return our_ret;
    }
    return their_ret;
}

static inline zval *zai_interceptor_get_zval_ptr(const zend_op *opline, int op_type, const znode_op *node, const zend_execute_data *execute_data) {
    switch (op_type) {
        case IS_CONST:
#if PHP_VERSION_ID >= 70300
            return RT_CONSTANT(opline, *node);
#else
            (void)opline;
            return EX_CONSTANT(*node);
#endif
        case IS_TMP_VAR:
        case IS_VAR:
        case IS_CV:
            return EX_VAR(node->var);
    }
    return NULL;
}
#define zai_interceptor_get_zval_ptr_op1(ex) zai_interceptor_get_zval_ptr((ex)->opline, (ex)->opline->op1_type, &(ex)->opline->op1, ex)
#define zai_interceptor_get_zval_ptr_op2(ex) zai_interceptor_get_zval_ptr((ex)->opline, (ex)->opline->op2_type, &(ex)->opline->op2, ex)

ZEND_TLS zend_op zai_interceptor_custom_return_op;
static inline void zai_interceptor_return_impl(zend_execute_data *execute_data) {
    zai_interceptor_frame_memory *frame_memory;
    if (zai_hook_memory_table_find(execute_data, &frame_memory)) {
        if (!frame_memory->implicit) {
            zval rv;
            zval *retval = zai_interceptor_get_zval_ptr_op1(execute_data);
            bool needs_copy = EX(opline)->op1_type == IS_CONST || EX(opline)->op1_type == IS_CV;
            if (Z_TYPE_INFO_P(retval) == IS_UNDEF) {
                ZVAL_NULL(&rv);
            } else {
                if (Z_TYPE_P(retval) == IS_INDIRECT) {
                    retval = Z_INDIRECT_P(retval);
                }
                rv = *retval;

                if (needs_copy) {
                    Z_TRY_ADDREF_P(retval);
                }
            }

            zai_hook_finish(execute_data, &rv, &frame_memory->hook_data);

            // Z_PTR_P() is fine to check with as we build for 64 bit pointer systems... Write it that way instead of memcmp to avoid uninit values
            // We need to check for null/undef separately thanks to the normalization handling above (can only be the case with IS_CV)
            if (Z_TYPE_INFO(rv) != Z_TYPE_INFO_P(retval)
             || (Z_TYPE_INFO(rv) <= IS_TRUE && (Z_TYPE_INFO_P(retval) != IS_UNDEF || Z_TYPE(rv) != IS_NULL))
             || Z_PTR(rv) != Z_PTR_P(retval)) {
                if (needs_copy) {
                    // If this branch is entered, the original retval will have been freed, don't free it again.

                    // We need a zval * within the 4GB of virtual memory after the execute_data...
                    // Thus make use of our temporary here
                    uint32_t temporary = zai_interceptor_find_temporary(&EX(func)->op_array);
                    if (temporary != -1u) {
                        zai_interceptor_custom_return_op = *EX(opline);
                        zai_interceptor_custom_return_op.op1_type = IS_VAR;
                        zai_interceptor_custom_return_op.op1.var = temporary;
                        // Replacing EX(opline) would generally not be fine, but given that ZEND_RETURN(_BY_REF) always leave the function, we can
                        // they never will do a HANDLE_EXCEPTION or access another opline relative to the current
                        EX(opline) = &zai_interceptor_custom_return_op;
                        ZVAL_COPY_VALUE(EX_VAR(temporary), &rv); // copy the const, as it's now a TMP it'll be freed
                    } else {
                        // it's sad, but we cannot support it properly right now if we don't find a temporary? shouldn't happen though
                        zval_ptr_dtor(&rv);
                    }
                } else {
                    // Override any IS_INDIRECT, hence directly to EX_VAR() instead of retval
                    ZVAL_COPY_VALUE(EX_VAR(EX(opline)->op1.var), &rv);
                }
            } else if (needs_copy) {
                zval_ptr_dtor_nogc(retval);
            }
        }
        zai_hook_memory_table_del(execute_data);
    }
}

static user_opcode_handler_t prev_return_handler;
static inline int zai_interceptor_return_handler_no_prev(zend_execute_data *execute_data) {
    if (ZEND_RETURN == EX(opline)->opcode) {
        zai_interceptor_return_impl(execute_data);
    }
    return ZEND_USER_OPCODE_DISPATCH;
}

static int zai_interceptor_return_handler(zend_execute_data *execute_data) {
    zai_interceptor_return_handler_no_prev(execute_data);
    return prev_return_handler(execute_data);
}

static user_opcode_handler_t prev_return_by_ref_handler;
static int zai_interceptor_return_by_ref_handler(zend_execute_data *execute_data) {
    if (ZEND_RETURN_BY_REF == EX(opline)->opcode) {
        zai_interceptor_return_impl(execute_data);
    }
    return prev_return_by_ref_handler ? prev_return_by_ref_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static user_opcode_handler_t prev_generator_return_handler;
static int zai_interceptor_generator_return_handler(zend_execute_data *execute_data) {
    if (ZEND_GENERATOR_RETURN == EX(opline)->opcode) {
        zai_interceptor_return_impl(execute_data);
    }
    return prev_generator_return_handler ? prev_generator_return_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static inline zend_op *zai_interceptor_get_next_catch_block(zend_execute_data *execute_data, zend_op *opline) {
    (void)execute_data;
#if PHP_VERSION_ID < 70300
    if (opline->result.num) {
        return NULL;
    }
#if PHP_VERSION_ID < 70100
    return &EX(func)->op_array.opcodes[opline->extended_value];
#else
    return ZEND_OFFSET_TO_OPLINE(opline, opline->extended_value);
#endif
#else
    if (opline->extended_value & ZEND_LAST_CATCH) {
        return NULL;
    }
    return OP_JMP_ADDR(opline, opline->op2);
#endif
}

static inline zend_class_entry *zai_interceptor_get_catching_ce(zend_execute_data *execute_data, const zend_op *opline) {
    zend_class_entry *catch_ce = NULL;
#if PHP_VERSION_ID < 70300
    catch_ce = CACHED_PTR(Z_CACHE_SLOT_P(EX_CONSTANT(opline->op1)));
    if (catch_ce == NULL) {
        catch_ce = zend_fetch_class_by_name(Z_STR_P(EX_CONSTANT(opline->op1)), EX_CONSTANT(opline->op1) + 1,
                                            ZEND_FETCH_CLASS_NO_AUTOLOAD);
    }
#elif PHP_VERSION_ID < 70400
    catch_ce = CACHED_PTR(opline->extended_value & ~ZEND_LAST_CATCH);
    if (catch_ce == NULL) {
        catch_ce = zend_fetch_class_by_name(Z_STR_P(RT_CONSTANT(opline, opline->op1)),
                                            RT_CONSTANT(opline, opline->op1) + 1, ZEND_FETCH_CLASS_NO_AUTOLOAD);
    }
#else
    catch_ce = CACHED_PTR(opline->extended_value & ~ZEND_LAST_CATCH);
    if (catch_ce == NULL) {
        catch_ce = zend_fetch_class_by_name(Z_STR_P(RT_CONSTANT(opline, opline->op1)),
                                         Z_STR_P(RT_CONSTANT(opline, opline->op1) + 1), ZEND_FETCH_CLASS_NO_AUTOLOAD);
    }
#endif
    return catch_ce;
}

static bool zai_interceptor_is_catching_frame(zend_execute_data *execute_data, const zend_op *throw_op) {
    zend_class_entry *ce, *catch_ce;
    zend_try_catch_element *try_catch;
    uint32_t throw_op_num = throw_op - EX(func)->op_array.opcodes;
    int i, current_try_catch_offset = -1;

    // TODO Handle exceptions thrown during function frame leaving to attach them to the right span? Maybe?

    // Find the innermost try/catch block the exception was thrown in
    for (i = 0; i < EX(func)->op_array.last_try_catch; i++) {
        try_catch = &EX(func)->op_array.try_catch_array[i];
        if (try_catch->try_op > throw_op_num) {
            // Exception was thrown before any remaining try/catch blocks
            break;
        }
        if (throw_op_num < try_catch->catch_op || throw_op_num < try_catch->finally_end) {
            current_try_catch_offset = i;
        }
    }

    while (current_try_catch_offset > -1) {
        try_catch = &EX(func)->op_array.try_catch_array[current_try_catch_offset];
        // Found a catch or finally block
        if (throw_op_num < try_catch->finally_op) {
            return true;
        }
        if (throw_op_num < try_catch->catch_op) {
            zend_op *opline = &EX(func)->op_array.opcodes[try_catch->catch_op];
            // Traverse all the catch blocks
            do {
                catch_ce = zai_interceptor_get_catching_ce(execute_data, opline);
                if (catch_ce != NULL) {
                    ce = EG(exception)->ce;
                    if (ce == catch_ce || instanceof_function(ce, catch_ce)) {
                        return true;
                    }
                }
                opline = zai_interceptor_get_next_catch_block(execute_data, opline);
            } while (opline != NULL);
        }
        current_try_catch_offset--;
    }

    return false;
}

static user_opcode_handler_t prev_fast_ret_handler;
static int zai_interceptor_fast_ret_handler(zend_execute_data *execute_data) {
    zai_interceptor_frame_memory *frame_memory;
    if (ZEND_FAST_RET == EX(opline)->opcode && zai_hook_memory_table_find(execute_data, &frame_memory)) {
        zval *fast_call = EX_VAR(EX(opline)->op1.var);
        // -1 when an exception exists
        if (fast_call->u2.lineno == (uint32_t)-1) {
            // The catching frame's span will get closed by the return handler so we leave it open
            if (zai_interceptor_is_catching_frame(execute_data, EX(opline)) == false) {
                if (!frame_memory->implicit) {
                    zval retval;
                    ZVAL_NULL(&retval);
                    EG(exception) = Z_OBJ_P(fast_call);
                    const zend_op *opline = EX(opline); // due to us lying about an exception existing, the sandbox will modify the opline
                    zai_hook_finish(execute_data, &retval, &frame_memory->hook_data);
                    EX(opline) = opline; // restore it
                    EG(exception) = NULL;
                }
                zai_hook_memory_table_del(execute_data);
            }
        }
    }

    return prev_fast_ret_handler ? prev_fast_ret_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

void zai_interceptor_check_for_opline_before_exception(zend_execute_data *execute_data);
static user_opcode_handler_t prev_handle_exception_handler;
static int zai_interceptor_handle_exception_handler(zend_execute_data *execute_data) {
    // not everything goes through zend_throw_exception_hook, in particular when zend_rethrow_exception alone is used (e.g. during zend_call_function)
    zai_interceptor_check_for_opline_before_exception(execute_data);

    zai_interceptor_frame_memory *frame_memory;
    if (ZEND_HANDLE_EXCEPTION == EX(opline)->opcode && zai_hook_memory_table_find(execute_data, &frame_memory)) {
        // The catching frame's span will get closed by the return handler so we leave it open
        if (zai_interceptor_is_catching_frame(execute_data, EG(opline_before_exception)) == false) {
            if (!frame_memory->implicit) {
                zval retval;
                ZVAL_NULL(&retval);
                zai_hook_finish(execute_data, &retval, &frame_memory->hook_data);
            }
            zai_hook_memory_table_del(execute_data);
        }
    }

    return prev_handle_exception_handler ? prev_handle_exception_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

void zai_interceptor_terminate_all_pending_observers() {
    zai_interceptor_frame_memory *frame_memory;
    zval retval;
    ZVAL_NULL(&retval);
    ZEND_HASH_REVERSE_FOREACH_PTR(&zai_hook_memory, frame_memory) {
        if (!frame_memory->implicit) {
            // the individual execute_data contents here may point to bogus (allocated) memory, but it's just used as key here, hence there's no issue
            zai_hook_finish(frame_memory->execute_data, &retval, &frame_memory->hook_data);
        }
    } ZEND_HASH_FOREACH_END();
    zend_hash_clean(&zai_hook_memory);
}

static zend_class_entry zai_interceptor_bailout_ce;
static zend_object_handlers zai_interceptor_bailout_handlers;
static int zai_interceptor_bailout_get_closure(zval *obj, zend_class_entry **ce_ptr, zend_function **fptr_ptr,
                                            zend_object **obj_ptr) {
    *fptr_ptr = (zend_function *)&zend_pass_function;
    *ce_ptr = &zai_interceptor_bailout_ce;
    *obj_ptr = Z_OBJ_P(obj);
    if (CG(unclean_shutdown)) {
        // we do this directly in the get_closure handler instead of a function to avoid an extra pushed stack frame in traces
        zai_interceptor_terminate_all_pending_observers();
    }
    return SUCCESS;
}

static void (*prev_execute_internal)(zend_execute_data *execute_data, zval *return_value);
static inline void zai_interceptor_execute_internal_impl(zend_execute_data *execute_data, zval *return_value, bool prev) {
    zend_function *func = execute_data->func;
    if (UNEXPECTED(zai_hook_installed_internal(&func->internal_function))) {
        zai_interceptor_frame_memory frame_memory;
        if (zai_hook_continue(execute_data, &frame_memory.hook_data) != ZAI_HOOK_CONTINUED) {
            goto skip;
        }
        frame_memory.execute_data = execute_data;
        frame_memory.implicit = false;
        zai_hook_memory_table_insert(execute_data, &frame_memory);

        // we do not use try / catch here as to preserve order of hooks, LIFO style, in bailout handler
        if (prev) {
            prev_execute_internal(execute_data, return_value);
        } else {
            func->internal_function.handler(execute_data, return_value);
        }

        zai_hook_finish(execute_data, return_value, &frame_memory.hook_data);
        zai_hook_memory_table_del(execute_data);
    } else {
        skip: ;
        if (prev) {
            prev_execute_internal(execute_data, return_value);
        } else {
            func->internal_function.handler(execute_data, return_value);
        }
    }
}

static void zai_interceptor_execute_internal_no_prev(zend_execute_data *execute_data, zval *return_value) {
    zai_interceptor_execute_internal_impl(execute_data, return_value, false);
}

static void zai_interceptor_execute_internal(zend_execute_data *execute_data, zval *return_value) {
    zai_interceptor_execute_internal_impl(execute_data, return_value, true);
}

static zend_generator *zai_interceptor_get_original_executing_generator(zend_generator *gen) {
   if (gen->node.children) {
       return (zend_generator *)(((char *)gen->execute_data->prev_execute_data) - (uintptr_t)(&((zend_generator*)0)->execute_fake));
   }
    return gen;
}

// Note: This is not optimized.
// I.e. a scenario where an observed generator yields from other generators which do a recursive yield from,
// every single yield in that generator will have an O(nested generators) performance.
// In real world I've yet to see excessive recursion of generators, but here is room for potential future optimizations
static void zai_interceptor_generator_yielded(zend_execute_data *ex, zval *key, zval *yielded, zai_interceptor_generator_frame_memory *frame_memory) {
    zend_generator *generator = (zend_generator *)ex->return_value, *leaf = zai_interceptor_get_original_executing_generator(generator);
    // yields happen inside out
    do {
        if (!frame_memory->frame.implicit) {
            frame_memory->resumed = false;
            zai_hook_generator_yielded(generator->execute_data, key, yielded, &frame_memory->frame.hook_data);
        }

        if (generator->node.children == 0) {
            break;
        }
        if (generator->node.children == 1) {
#if PHP_VERSION_ID < 70300
            generator = generator->node.child.array[0].child;
#else
            generator = generator->node.child.single.child;
#endif
        } else {
            /* As per get_new_root():
             * We have reached a multi-child node haven't found the root yet. We don't know which
             * child to follow, so perform the search from the other direction instead. */
            zend_generator *child = leaf;
            while (child->node.parent != generator) {
                child = child->node.parent;
            }
            generator = child;
        }
    } while (zai_hook_memory_table_find(generator->execute_data, (zai_interceptor_frame_memory **) &frame_memory));
}

static void zai_interceptor_generator_resumption(zend_execute_data *ex, zval *sent, zai_interceptor_generator_frame_memory *frame_memory) {
    zend_generator *generator = zai_interceptor_get_original_executing_generator((zend_generator *)ex->return_value);
    // resumptions occur from outside to inside
    do {
        if (zai_hook_memory_table_find(generator->execute_data, (zai_interceptor_frame_memory **) &frame_memory)) {
            if (!frame_memory->frame.implicit && !frame_memory->resumed) {
                frame_memory->resumed = true;
                zai_hook_generator_resumption(generator->execute_data, sent, &frame_memory->frame.hook_data);
            }
        }
    } while ((generator = generator->node.parent));
}

static zend_op generator_resumption_op_template;
static void zai_interceptor_install_generator_resumption_op(zai_interceptor_generator_frame_memory *generator) {
    generator->resumption_ops[1] = generator_resumption_op_template;
    generator->resumption_ops[1].lineno = generator->resumption_ops[0].lineno;
}

static zend_object_dtor_obj_t zai_interceptor_generator_dtor_obj;
static void zai_interceptor_generator_dtor_wrapper(zend_object *object) {
    zend_generator *generator = (zend_generator *)object;
    zend_execute_data *execute_data = generator->execute_data;

    zai_interceptor_generator_frame_memory *gen_memory;
    if (execute_data && zai_hook_memory_table_find(execute_data, (zai_interceptor_frame_memory **) &gen_memory)) {
        if (execute_data->opline == &gen_memory->resumption_ops[1]) {
            execute_data->opline = gen_memory->return_op;
        }

        if (UNEXPECTED(!(EX(func)->op_array.fn_flags & ZEND_ACC_HAS_FINALLY_BLOCK))) {
            /* Find next finally block */
            int op_num = EX(opline) - EX(func)->op_array.opcodes - 1;
            // this condition is typically true... unless we have a neighbour messing with oplines (just like ourselves)
            if (op_num >= 0 && (uint32_t)op_num < EX(func)->op_array.last) {
                uint32_t finally_op_num = 0;
                for (int i = 0; i < EX(func)->op_array.last_try_catch; i++) {
                    zend_try_catch_element *try_catch = &EX(func)->op_array.try_catch_array[i];

                    if ((uint32_t)op_num < try_catch->try_op) {
                        break;
                    }

                    if ((uint32_t)op_num < try_catch->finally_op) {
                        finally_op_num = try_catch->finally_op;
                    }
                }
                if (finally_op_num) {
                    generator->flags |= ZEND_GENERATOR_FORCED_CLOSE;
                    zai_hook_generator_resumption(execute_data, &EG(uninitialized_zval), &gen_memory->frame.hook_data);
                }
            }
        }
    }

    zai_interceptor_generator_dtor_obj(object);

    if (execute_data && zai_hook_memory_table_find(execute_data, (zai_interceptor_frame_memory **) &gen_memory)) {
        if (!gen_memory->frame.implicit) {
            zval retval;
            ZVAL_NULL(&retval);
            if (!Z_ISUNDEF(generator->retval)) {
                ZVAL_COPY_VALUE(&retval, &generator->retval);
            }
            zai_hook_finish(execute_data, &retval, &gen_memory->frame.hook_data);
        }
        zai_hook_memory_table_del(execute_data);
    }
}

static zend_object_handlers *zai_interceptor_generator_handlers;
static pthread_once_t zai_interceptor_replace_generator_dtor_once = PTHREAD_ONCE_INIT;
static void zai_interceptor_replace_generator_dtor(void) {
    zai_interceptor_generator_dtor_obj = zai_interceptor_generator_handlers->dtor_obj;
    zai_interceptor_generator_handlers->dtor_obj = zai_interceptor_generator_dtor_wrapper;
}

static zend_object *(*generator_create_prev)(zend_class_entry *class_type);
static zend_object *zai_interceptor_generator_create(zend_class_entry *class_type) {
    zend_generator *generator = (zend_generator *)generator_create_prev(class_type);
#if PHP_VERSION_ID < 70100
    zend_execute_data *execute_data = (zend_execute_data *)ZEND_VM_STACK_ELEMETS(EG(vm_stack));
    if ((EX_CALL_INFO() & ZEND_CALL_ALLOCATED) && execute_data->prev_execute_data == NULL) {
        zai_interceptor_generator_frame_memory gen_memory;
        if (zai_hook_continue(execute_data, &gen_memory.frame.hook_data) == ZAI_HOOK_CONTINUED) {
            gen_memory.frame.execute_data = execute_data;
            gen_memory.resumed = false;
            gen_memory.temporary = zai_interceptor_find_temporary(&EX(func)->op_array);
            gen_memory.frame.implicit = false;

            zai_interceptor_generator_frame_memory *memory_ptr = zai_hook_memory_table_insert_generator(execute_data,
                                                                                                        &gen_memory);

            memory_ptr->resumption_ops[0].lineno = EX(opline)->lineno;
            zai_interceptor_install_generator_resumption_op(memory_ptr);
            memory_ptr->return_op = EX(opline);
            EX(opline) = (const zend_op *) &memory_ptr->resumption_ops[1];
        }
    }
#endif

    zai_interceptor_generator_handlers = (zend_object_handlers *)generator->std.handlers;
    pthread_once(&zai_interceptor_replace_generator_dtor_once, zai_interceptor_replace_generator_dtor);

    return &generator->std;
}

#if PHP_VERSION_ID >= 70100
// On PHP 7.1+ calling a generator does not directly instantiate a generator, but enters a new execute_data frame, which invokes ZEND_GENERATOR_CREATE
// ZEND_GENERATOR_CREATE in turn then copies the current frame onto a newly allocated chunk of memory,
// which represents the execute_data for all future operations within that generator.
// We thus must move the started hook data which we reference by execute_data to the new address

// Constraints imposed by the VM:
// - No direct change of the execute_data pointer (only its contents)
// - ZEND_GENERATOR_CREATE will invoke leave_helper
// - When returning full control to the VM, execute_data must be on the stack (i.e. EG(vm_stack_top) must be correct)
// Thus, we cannot directly set our own opcode to be executed after ZEND_GENERATOR_CREATE.
// We work around this by installing a custom return address (i.e. setting EX(prev_execute_data) to our own frame), with our own opcode
// To ensure stack consistency however, we need to ensure we return from a proper VM stack, thus, we push a frame
//  (to which we need to leave, because we cannot directly replace execute_data)
// Ultimately we can then return from that pushed stack frame (second invacation of the opcode handler).

#if PHP_VERSION_ID >= 70400
#define ZEND_SET_CALL_INFO(call, object, info) do { \
        ZEND_CALL_INFO(call) = info; \
    } while (0)
#endif

static zend_op_array zai_interceptor_empty_op_array;
static zend_op zai_interceptor_generator_create_wrapper[2];
static user_opcode_handler_t prev_post_generator_create_handler;
ZEND_TLS zend_execute_data zai_interceptor_generator_create_frame;
static int zai_interceptor_post_generator_create_handler(zend_execute_data *execute_data) {
    if (EX(opline) == &zai_interceptor_generator_create_wrapper[0] || EX(opline) == &zai_interceptor_generator_create_wrapper[1]) {
        // working around the fact that we cannot modify execute_data directly here.
        // pushing our own frame here is necessary to ensure a consistent EG(vm_stack)
        if (execute_data == &zai_interceptor_generator_create_frame) {
            // first invocation
#if PHP_VERSION_ID < 70400
            zend_execute_data *frame = zend_vm_stack_push_call_frame_ex(sizeof(zend_execute_data), EX_CALL_INFO(), EX(func), 0, NULL, NULL);
#else
            zend_execute_data *frame = zend_vm_stack_push_call_frame_ex(sizeof(zend_execute_data), EX_CALL_INFO(), EX(func), 0, NULL);
#endif
            frame->opline = zai_interceptor_generator_create_wrapper;
            frame->prev_execute_data = EX(prev_execute_data);

            EX(prev_execute_data) = frame;
            ZEND_SET_CALL_INFO(execute_data, 0, EX_CALL_INFO() & ~ZEND_CALL_TOP);

            // Now update the execute_data pointer properly
            zend_execute_data *initial_generator_ex = Z_PTR(EX(This));
            zend_execute_data *new_generator_ex = ((zend_generator *)Z_OBJ_P(EX(return_value)))->execute_data;
            zai_interceptor_frame_memory *frame_memory;
            if (zai_hook_memory_table_find(initial_generator_ex, &frame_memory)) {
                frame_memory->execute_data = new_generator_ex;
                zai_interceptor_generator_frame_memory generator_frame;
                generator_frame.frame = *frame_memory;
                generator_frame.resumed = false;
                generator_frame.temporary = zai_interceptor_find_temporary(&new_generator_ex->func->op_array);
                zai_interceptor_generator_frame_memory *gen_memory = zai_hook_memory_table_insert_generator(new_generator_ex, &generator_frame);
                zai_hook_memory_table_del(initial_generator_ex);

                // Setup detection for resumption
                const zend_op *opline = new_generator_ex->opline;
                gen_memory->return_op = opline;
                gen_memory->resumption_ops[0].lineno = opline[-1].lineno;
                zai_interceptor_install_generator_resumption_op(gen_memory);
                new_generator_ex->opline = &gen_memory->resumption_ops[1];
            }
        } else {
            // second invocation
            // leaving the initial frame sets EG(vm_stack_top) to its address. Fix this for (asserted) vm_stack consistency
            EG(vm_stack_top) = (zval *)(execute_data + 1);
        }
        return ZEND_USER_OPCODE_RETURN; // this returns
    } else if (prev_post_generator_create_handler) {
        return prev_post_generator_create_handler(execute_data);
    } else {
        return ZEND_NOP; // should be unreachable, but don't crash?
    }
}

// Push a fake prev_execute_data to gain control after ZEND_GENERATOR_CREATE returns:
// we need access to the allocated generator execute_data pointer
static user_opcode_handler_t prev_generator_create_handler;
static int zai_interceptor_generator_create_handler(zend_execute_data *execute_data) {
    zai_interceptor_frame_memory *frame_memory;
    if (ZEND_GENERATOR_CREATE == EX(opline)->opcode && zai_hook_memory_table_find(execute_data, &frame_memory)) {
        // ZEND_CALL_TOP means execute_ex (the VM) will return. We don't want to return immediately, but continue
        // execution in our own execute_data frame. Drop this flag if present and preserve it for later restoring.
        int top_flag = EX_CALL_INFO() & ZEND_CALL_TOP;
        ZEND_SET_CALL_INFO(execute_data, Z_TYPE(EX(This)), EX_CALL_INFO() & ~ZEND_CALL_TOP);

        zval *retval = EX(return_value);

        zend_execute_data *prev = EX(prev_execute_data);
        EX(prev_execute_data) = &zai_interceptor_generator_create_frame;
        Z_PTR(zai_interceptor_generator_create_frame.This) = execute_data; // some place to store it

        execute_data = EX(prev_execute_data);
        EX(opline) = zai_interceptor_generator_create_wrapper;
        EX(return_value) = retval;
        EX(prev_execute_data) = prev;
        EX(func) = (zend_function *) &zai_interceptor_empty_op_array; // for i_free_compiled_variables
        ZEND_SET_CALL_INFO(execute_data, 0, top_flag);
        EX_NUM_ARGS() = 0;
    }

    return prev_generator_create_handler ? prev_generator_create_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}
#endif

static void zai_interceptor_setup_generator_resumption_ops(zend_execute_data *execute_data, zai_interceptor_generator_frame_memory *gen_memory) {
    // Setup detection for resumption
    const zend_op *opline = EX(opline);
    gen_memory->return_op = opline + 1;
    gen_memory->resumption_ops[0] = *opline;
    zai_interceptor_install_generator_resumption_op(gen_memory);
#if PHP_VERSION_ID >= 70300 && !ZEND_USE_ABS_CONST_ADDR
    // on PHP 7.3+ literals are opline-relative and thus need to be relocated
    zval *constant = (zval *)&gen_memory->resumption_ops[2];
    if (opline->op1_type == IS_CONST) {
        ZVAL_COPY_VALUE(&constant[0], RT_CONSTANT(opline, opline->op1));
        gen_memory->resumption_ops[0].op1.constant = sizeof(zend_op) * 2;
    }
    if (opline->op2_type == IS_CONST) {
        ZVAL_COPY_VALUE(&constant[1], RT_CONSTANT(opline, opline->op2));
        gen_memory->resumption_ops[0].op2.constant = sizeof(zend_op) * 2 + sizeof(zval);
    }
#endif
    EX(opline) = &gen_memory->resumption_ops[0];
}

// ZEND_YIELD may throw
// (exception from dtor of previous key or value or from exception in error handler when returning a non-reference value from a by-ref generator)
// These throwing conditions are not reasonably catchable due to ZEND_YIELD doing a direct ZEND_VM_RETURN.
// Thus, in these rare conditions we report that a yield has happened despite it actually not going to happen.
// This is a limitation of what's possible in PHP 7.
// It would be possible to alter the return address of execute_ex with a couple lines of assembly,
// but this is a platform and ABI specific hack I'm not comfortable relying on here,
// also considering compatibility with other extensions possibly doing similar hacks, leading to crashes.
static user_opcode_handler_t prev_yield_handler;
static int zai_interceptor_yield_handler(zend_execute_data *execute_data) {
    zai_interceptor_generator_frame_memory *gen_memory;
    if (ZEND_YIELD == EX(opline)->opcode && zai_hook_memory_table_find(execute_data, (zai_interceptor_frame_memory **) &gen_memory)) {
        zend_generator *generator = (zend_generator *) EX(return_value);
        if (EXPECTED((generator->flags & ZEND_GENERATOR_FORCED_CLOSE) == 0)) {
            zval *value = zai_interceptor_get_zval_ptr_op1(execute_data);
            if (value == NULL) {
                value = &EG(uninitialized_zval);
            }
            zval *key = zai_interceptor_get_zval_ptr_op2(execute_data), default_key;
            if (key == NULL) {
                ZVAL_LONG(&default_key, generator->largest_used_integer_key + 1);
                key = &default_key;
            }
            zai_interceptor_generator_yielded(execute_data, key, value, gen_memory);
            zai_interceptor_setup_generator_resumption_ops(execute_data, gen_memory);
        }
    }

    return prev_yield_handler ? prev_yield_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

typedef struct {
    zend_object_iterator it;
    union {
        zend_array *array;
        zend_object_iterator *iterator;
        zval zv;
    };
    zend_generator *generator;
    zai_interceptor_generator_frame_memory *frame_memory;
    zval last_value;
} zai_interceptor_iterator_wrapper;

#if PHP_VERSION_ID < 70300
#define CONST_ITERATOR_FUNCS
#else
#define CONST_ITERATOR_FUNCS const
#endif

static void zai_interceptor_iterator_wrapper_dtor(zend_object_iterator *it) {
    zval_ptr_dtor(&((zai_interceptor_iterator_wrapper *)it)->zv);
}

/*
static HashTable *zai_interceptor_iterator_wrapper_get_gc(zend_object_iterator *it, zval **table, int *n) {
    *table = &((zai_interceptor_iterator_wrapper *)it)->zv;
    *n = 1;
    return NULL;
}
*/

static int zai_interceptor_iterator_wrapper_iterator_valid(zend_object_iterator *iter) {
    zai_interceptor_iterator_wrapper *it = (zai_interceptor_iterator_wrapper *)iter;
    it->iterator->index = it->it.index;
    return it->iterator->funcs->valid(it->iterator);
}

static zval *zai_interceptor_iterator_wrapper_iterator_get_current_data(zend_object_iterator *iter) {
    zai_interceptor_iterator_wrapper *it = (zai_interceptor_iterator_wrapper *)iter;
    ZVAL_COPY_VALUE(&it->last_value, it->iterator->funcs->get_current_data(it->iterator));
    return &it->last_value;
}

static void zai_interceptor_iterator_wrapper_iterator_get_current_key(zend_object_iterator *iter, zval *key) {
    zai_interceptor_iterator_wrapper *it = (zai_interceptor_iterator_wrapper *)iter;
    if (it->iterator->funcs->get_current_key) {
        it->iterator->funcs->get_current_key(it->iterator, key);
    } else {
        ZVAL_LONG(key, iter->index);
    }
    if (!EG(exception)) {
        zai_interceptor_generator_yielded(it->generator->execute_data, key, &it->last_value, it->frame_memory);
    }
}

static void zai_interceptor_iterator_wrapper_iterator_move_forward(zend_object_iterator *iter) {
    zai_interceptor_iterator_wrapper *it = (zai_interceptor_iterator_wrapper *)iter;
    it->iterator->index = it->it.index;
    zai_interceptor_generator_resumption(it->generator->execute_data, &EG(uninitialized_zval), it->frame_memory);
    // skip on ex/last
    it->iterator->funcs->move_forward(it->iterator);
}

static void zai_interceptor_iterator_wrapper_iterator_rewind(zend_object_iterator *iter) {
    zai_interceptor_iterator_wrapper *it = (zai_interceptor_iterator_wrapper *)iter;
    if (it->iterator->funcs->rewind) {
        it->iterator->funcs->rewind(it->iterator);
    }
}

static CONST_ITERATOR_FUNCS zend_object_iterator_funcs zai_interceptor_iterator_wrapper_iterator_funcs = {
        .dtor = zai_interceptor_iterator_wrapper_dtor,
        //.get_gc = zai_interceptor_iterator_wrapper_get_gc,
        .valid = zai_interceptor_iterator_wrapper_iterator_valid,
        .get_current_data = zai_interceptor_iterator_wrapper_iterator_get_current_data,
        .get_current_key = zai_interceptor_iterator_wrapper_iterator_get_current_key,
        .move_forward = zai_interceptor_iterator_wrapper_iterator_move_forward,
        .invalidate_current = NULL,
        .rewind = zai_interceptor_iterator_wrapper_iterator_rewind,
};

static int zai_interceptor_iterator_wrapper_array_valid(zend_object_iterator *iter) {
    zai_interceptor_iterator_wrapper *it = (zai_interceptor_iterator_wrapper *)iter;
    return zend_hash_get_current_data_ex(it->array, &Z_FE_POS(it->zv)) ? SUCCESS : FAILURE;
}

static zval *zai_interceptor_iterator_wrapper_array_get_current_data(zend_object_iterator *iter) {
    zai_interceptor_iterator_wrapper *it = (zai_interceptor_iterator_wrapper *)iter;
    return zend_hash_get_current_data_ex(it->array, &Z_FE_POS(it->zv));
}

static void zai_interceptor_iterator_wrapper_array_get_current_key(zend_object_iterator *iter, zval *key) {
    zai_interceptor_iterator_wrapper *it = (zai_interceptor_iterator_wrapper *)iter;
    zend_hash_get_current_key_zval_ex(it->array, key, &Z_FE_POS(it->zv));
    zval *yielded = zend_hash_get_current_data_ex(it->array, &Z_FE_POS(it->zv));
    zai_interceptor_generator_yielded(it->generator->execute_data, key, yielded, it->frame_memory);
}

static void zai_interceptor_iterator_wrapper_array_move_forward(zend_object_iterator *iter) {
    zai_interceptor_iterator_wrapper *it = (zai_interceptor_iterator_wrapper *)iter;
    zai_interceptor_generator_resumption(it->generator->execute_data, &EG(uninitialized_zval), it->frame_memory);
    zend_hash_move_forward_ex(it->array, &Z_FE_POS(it->zv));
}

static CONST_ITERATOR_FUNCS zend_object_iterator_funcs zai_interceptor_iterator_wrapper_array_funcs = {
        .dtor = zai_interceptor_iterator_wrapper_dtor,
        //.get_gc = zai_interceptor_iterator_wrapper_get_gc,
        .valid = zai_interceptor_iterator_wrapper_array_valid,
        .get_current_data = zai_interceptor_iterator_wrapper_array_get_current_data,
        .get_current_key = zai_interceptor_iterator_wrapper_array_get_current_key,
        .move_forward = zai_interceptor_iterator_wrapper_array_move_forward,
        .invalidate_current = NULL,
        .rewind = NULL,
};

static zend_object_iterator *zai_interceptor_yield_from_wrapped_iterator(zend_class_entry *ce, zval *object, int by_ref) {
    zval *original = OBJ_PROP_NUM(Z_OBJ_P(object), 0);
    zai_interceptor_iterator_wrapper *it = ecalloc(1, sizeof(*it));
    it->generator = Z_PTR_P(OBJ_PROP_NUM(Z_OBJ_P(object), 1));
    it->frame_memory = Z_PTR_P(OBJ_PROP_NUM(Z_OBJ_P(object), 2));

    // we need to restore it immediately if this was a VAR or CV to not leak our internal wrapper object
    zval tmp;
    ZVAL_COPY_VALUE(&tmp, object);
    ZVAL_COPY_VALUE(object, original);
    ZVAL_NULL(original);
    zval_ptr_dtor(&tmp);

    if (Z_TYPE_P(object) == IS_ARRAY) {
        it->it.funcs = &zai_interceptor_iterator_wrapper_array_funcs;
        ZVAL_COPY(&it->zv, object);
    } else {
        zend_object_iterator *iter = ce->parent->get_iterator(ce->parent, object, by_ref);

        if (!iter || EG(exception)) {
            efree(it);
            return iter;
        }
        it->it.funcs = &zai_interceptor_iterator_wrapper_iterator_funcs;
        ZVAL_OBJ(&it->zv, &iter->std);
    }

    zend_iterator_init(&it->it);
    return &it->it;
}

ZEND_TLS zend_class_entry yield_from_iterator_wrapper_class = {0};
static user_opcode_handler_t prev_yield_from_handler;
static int zai_interceptor_yield_from_handler(zend_execute_data *execute_data) {
    zai_interceptor_generator_frame_memory *gen_memory;
    if (ZEND_YIELD_FROM == EX(opline)->opcode && zai_hook_memory_table_find(execute_data, (zai_interceptor_frame_memory **) &gen_memory)) {
        zend_generator *generator = (zend_generator *) EX(return_value);
        if (EXPECTED((generator->flags & ZEND_GENERATOR_FORCED_CLOSE) == 0)) {
            // There are two cases here:
            // a) yield from array or iterator
            //    Here we can just wrap the iterator or array into our custom iterator, transparently without observable side effects
            // b) yield from generator
            //    It isn't possible to wrap a generator, because using ->send() or ->throw() would not forward the values up in the generator chain;
            //    even worse, using ->throw() stops an iterator completely. Thus our only choice is instrumenting the yielded from generator directly.

            // Now we have our own copy of the yield from op.
            // In case we have an IS_CONST, we need to replace it by a TMP to be able to use the iterator-path. (only can happen with arrays)
            zai_interceptor_setup_generator_resumption_ops(execute_data, gen_memory);

            zval *val = zai_interceptor_get_zval_ptr_op1(execute_data);
            if (Z_TYPE_P(val) == IS_ARRAY) {
                // We control that opline thanks to the replacing before. We can change it at will.
                if (EX(opline)->op1_type == IS_CONST) {
                    // We need a zval * within the 4GB of virtual memory after the execute_data...
                    // Thus make use of our temporary here
                    if (gen_memory->temporary == -1u) {
                        // it's sad, but we cannot support this properly right now?
                        goto end;
                    }

                    ((zend_op *)EX(opline))->op1_type = IS_TMP_VAR;
                    zval *newtemp = EX_VAR(gen_memory->temporary);
                    ZVAL_COPY(newtemp, val); // copy the const, as it's now a TMP it'll be freed
                    val = newtemp;
                    ((zend_op *)EX(opline))->op1.var = gen_memory->temporary;
                }

                goto install_wrapper_iterator;
            } else if (Z_TYPE_P(val) == IS_OBJECT && Z_OBJCE_P(val)->get_iterator) {
                zend_class_entry *ce = Z_OBJCE_P(val);
                if (ce == zend_ce_generator) {
                    zend_generator *from = (zend_generator *) Z_OBJ_P(val), *root = zend_generator_get_current(from);
                    if (Z_ISUNDEF(generator->retval) && root != generator) {
                        // a yielded from generator needs begin/end handlers to track yields etc.
                        // it needs to keep track which parent generators are yielding from
                        // as opposed to PHP itself, which is only interested in the active leaf and the current root
                        // we need to observe if current generator is observed.
                        // it is enough to check whether the parent is observed, otherwise we can abort.
                        // if we encounter a non-observed generator, we must mark all instances of that generators yield from chain as observed
                        if (!Z_ISUNDEF(root->value)) {
                            zai_interceptor_generator_yielded(execute_data, &root->key, &root->value, gen_memory);
                        }

                        generator = from;
                        while (generator && !zai_hook_memory_table_find(generator->execute_data, (zai_interceptor_frame_memory **) &gen_memory)) {
                            zai_interceptor_generator_frame_memory new_gen_memory;
                            new_gen_memory.frame.implicit = true;
                            new_gen_memory.resumed = false;
                            zai_hook_memory_table_insert_generator(generator->execute_data, &new_gen_memory);
                            generator = generator->node.parent;
                        }
                    }
                } else {
                    // Use a temporary ce which can return a zend_object_iterator (encompassing arrays)
                    yield_from_iterator_wrapper_class.name = ce->name;
                    yield_from_iterator_wrapper_class.parent = ce;

install_wrapper_iterator: ;
                    yield_from_iterator_wrapper_class.get_iterator = zai_interceptor_yield_from_wrapped_iterator;
                    yield_from_iterator_wrapper_class.default_properties_count = 3;
                    zend_object *obj = zend_objects_new(&yield_from_iterator_wrapper_class);
                    ZVAL_COPY_VALUE(OBJ_PROP_NUM(obj, 0), val);
                    ZVAL_PTR(OBJ_PROP_NUM(obj, 1), generator);
                    ZVAL_PTR(OBJ_PROP_NUM(obj, 2), gen_memory);
                    ZVAL_OBJ(val, obj);
                }
            }
        }
    }

end:
    return prev_yield_from_handler ? prev_yield_from_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

#define ZAI_INTERCEPTOR_GENERATOR_RESUMPTION_OP 225 // random 8 bit number greater than ZEND_VM_LAST_OPCODE
static user_opcode_handler_t prev_generator_resumption_handler;
static int zai_interceptor_generator_resumption_handler(zend_execute_data *execute_data) {
    if (execute_data->opline->opcode == ZAI_INTERCEPTOR_GENERATOR_RESUMPTION_OP) {
        zai_interceptor_generator_frame_memory *gen_memory;
        if (zai_hook_memory_table_find(execute_data, (zai_interceptor_frame_memory **) &gen_memory)) {
            if (execute_data->opline == &gen_memory->resumption_ops[1]) {
                zend_generator *generator = (zend_generator *) EX(return_value);
                zval *sent = !EG(exception) && generator->send_target ? generator->send_target : &EG(uninitialized_zval);
                zai_interceptor_generator_resumption(execute_data, sent, gen_memory);

                EX(opline) = gen_memory->return_op;
            }
        }
        return ZEND_USER_OPCODE_CONTINUE;
    } else if (prev_generator_resumption_handler) {
        return prev_generator_resumption_handler(execute_data);
    } else {
        return ZEND_NOP; // should be unreachable, but don't crash?
    }
}

static void (*prev_exception_hook)(zval *);
static void zai_interceptor_exception_hook(zval *ex) {
    zai_interceptor_generator_frame_memory *gen_memory;
    if (zai_hook_memory_table_find(EG(current_execute_data), (zai_interceptor_frame_memory **) &gen_memory)) {
        if (ZEND_USER_CODE(EG(current_execute_data)->func->type)) {
            // check against resumption_ops[0]: when throwing the engine rolls back to the original yielding opcode (for correct stacktraces)
            if (EG(current_execute_data)->opline == &gen_memory->resumption_ops[0]) {
                // called right before setting EG(opline_before_exception), reset to original value to ensure correct throw_op handling
                EG(current_execute_data)->opline = gen_memory->return_op - 1;
                zai_interceptor_generator_resumption(EG(current_execute_data), &EG(uninitialized_zval), gen_memory);
            } else if (EG(current_execute_data)->opline == &gen_memory->resumption_ops[1]) {
                // however on versions before php-src commit 2e9e706a8271bbb42ad696c3383912facdd7d45f (< 7.3.23, < 7.4.11)
                // the resumption op is not reset. Handle this case here (and mirror the slightly buggy behaviour...).
                EG(current_execute_data)->opline = gen_memory->return_op;
                zai_interceptor_generator_resumption(EG(current_execute_data), &EG(uninitialized_zval), gen_memory);
            }
        }
    }
    if (prev_exception_hook) {
        prev_exception_hook(ex);
    }
}

void zai_interceptor_setup_resolving_post_startup(void);

#if PHP_VERSION_ID >= 70300
static int (*prev_post_startup)(void);
int zai_interceptor_post_startup(void) {
    int result = prev_post_startup ? prev_post_startup() : SUCCESS; // first run opcache post_startup, then ours

    zai_hook_post_startup();
    zai_interceptor_setup_resolving_post_startup();

    return result;
}
#endif

void zai_interceptor_startup(zend_module_entry *module_entry) {
    prev_execute_internal = zend_execute_internal;
    zend_execute_internal = prev_execute_internal ? zai_interceptor_execute_internal : zai_interceptor_execute_internal_no_prev;

    // init
    prev_ext_nop_handler = zend_get_user_opcode_handler(ZEND_EXT_NOP);
    zend_set_user_opcode_handler(ZEND_EXT_NOP, prev_ext_nop_handler ? zai_interceptor_ext_nop_handler : zai_interceptor_ext_nop_handler_no_prev);

    // end
    prev_return_handler = zend_get_user_opcode_handler(ZEND_RETURN);
    user_opcode_handler_t return_handler = prev_return_handler ? zai_interceptor_return_handler : zai_interceptor_return_handler_no_prev;
    zend_set_user_opcode_handler(ZEND_RETURN, return_handler);
    prev_return_by_ref_handler = zend_get_user_opcode_handler(ZEND_RETURN_BY_REF);
    zend_set_user_opcode_handler(ZEND_RETURN_BY_REF, zai_interceptor_return_by_ref_handler);
    prev_generator_return_handler = zend_get_user_opcode_handler(ZEND_GENERATOR_RETURN);
    zend_set_user_opcode_handler(ZEND_GENERATOR_RETURN, zai_interceptor_generator_return_handler);
    prev_handle_exception_handler = zend_get_user_opcode_handler(ZEND_HANDLE_EXCEPTION);
    zend_set_user_opcode_handler(ZEND_HANDLE_EXCEPTION, zai_interceptor_handle_exception_handler);
    prev_fast_ret_handler = zend_get_user_opcode_handler(ZEND_FAST_RET);
    zend_set_user_opcode_handler(ZEND_FAST_RET, zai_interceptor_fast_ret_handler);
    prev_yield_handler = zend_get_user_opcode_handler(ZEND_YIELD);
    zend_set_user_opcode_handler(ZEND_YIELD, zai_interceptor_yield_handler);
    prev_yield_from_handler = zend_get_user_opcode_handler(ZEND_YIELD_FROM);
    zend_set_user_opcode_handler(ZEND_YIELD_FROM, zai_interceptor_yield_from_handler);

    prev_generator_resumption_handler = zend_get_user_opcode_handler(ZAI_INTERCEPTOR_GENERATOR_RESUMPTION_OP);
    zend_set_user_opcode_handler(ZAI_INTERCEPTOR_GENERATOR_RESUMPTION_OP, zai_interceptor_generator_resumption_handler);

    generator_resumption_op_template.opcode = ZAI_INTERCEPTOR_GENERATOR_RESUMPTION_OP;
    SET_UNUSED(generator_resumption_op_template.result);
    SET_UNUSED(generator_resumption_op_template.op1);
    SET_UNUSED(generator_resumption_op_template.op2);
    ZEND_VM_SET_OPCODE_HANDLER(&generator_resumption_op_template);

    prev_exception_hook = zend_throw_exception_hook;
    zend_throw_exception_hook = zai_interceptor_exception_hook;

#ifndef ZTS
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op));
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op)+1);
    ZEND_VM_SET_OPCODE_HANDLER(EG(exception_op)+2);
#endif

    // generator intercepting weirdness
    generator_create_prev = zend_ce_generator->create_object;
    zend_ce_generator->create_object = zai_interceptor_generator_create;
#if PHP_VERSION_ID >= 70100
#define ZAI_INTERCEPTOR_POST_GENERATOR_CREATE_OP 224 // random 8 bit number greater than ZEND_VM_LAST_OPCODE
    prev_post_generator_create_handler = zend_get_user_opcode_handler(ZAI_INTERCEPTOR_POST_GENERATOR_CREATE_OP);
    zend_set_user_opcode_handler(ZAI_INTERCEPTOR_POST_GENERATOR_CREATE_OP, zai_interceptor_post_generator_create_handler);
    prev_generator_create_handler = zend_get_user_opcode_handler(ZEND_GENERATOR_CREATE);
    zend_set_user_opcode_handler(ZEND_GENERATOR_CREATE, zai_interceptor_generator_create_handler);

    zai_interceptor_generator_create_wrapper[0].opcode = ZAI_INTERCEPTOR_POST_GENERATOR_CREATE_OP;
    SET_UNUSED(zai_interceptor_generator_create_wrapper[0].result);
    SET_UNUSED(zai_interceptor_generator_create_wrapper[0].op1);
    SET_UNUSED(zai_interceptor_generator_create_wrapper[0].op2);
    ZEND_VM_SET_OPCODE_HANDLER(&zai_interceptor_generator_create_wrapper[0]);
    zai_interceptor_generator_create_wrapper[1].opcode = ZAI_INTERCEPTOR_POST_GENERATOR_CREATE_OP;
    SET_UNUSED(zai_interceptor_generator_create_wrapper[1].result);
    SET_UNUSED(zai_interceptor_generator_create_wrapper[1].op1);
    SET_UNUSED(zai_interceptor_generator_create_wrapper[1].op2);
    ZEND_VM_SET_OPCODE_HANDLER(&zai_interceptor_generator_create_wrapper[1]);
#endif

    INIT_NS_CLASS_ENTRY(zai_interceptor_bailout_ce, "Zend Abstract Interface", "BailoutHandler", NULL);
    zai_interceptor_bailout_ce.type = ZEND_INTERNAL_CLASS;
    zend_initialize_class_data(&zai_interceptor_bailout_ce, false);
    zai_interceptor_bailout_ce.info.internal.module = module_entry;
    memcpy(&zai_interceptor_bailout_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    zai_interceptor_bailout_handlers.get_closure = zai_interceptor_bailout_get_closure;

#if PHP_VERSION_ID >= 70300
    prev_post_startup = zend_post_startup_cb;
    zend_post_startup_cb = zai_interceptor_post_startup;
#else
    zai_hook_post_startup();
    zai_interceptor_setup_resolving_post_startup();
#endif
}

static void zai_hook_memory_dtor(zval *zv) {
    efree(Z_PTR_P(zv));
}

void zai_interceptor_reset_resolver(void);
void zai_interceptor_activate() {
    zai_interceptor_reset_resolver();

    zend_hash_init(&zai_hook_memory, 8, nothing, zai_hook_memory_dtor, 0);
}

void zai_interceptor_rinit() {
    // install bailout handler - shutdown functions are the first thing we can reliably hook into in case of bailout
    php_shutdown_function_entry shutdown_function = {.arguments = emalloc(sizeof(zval)), .arg_count = 1};
    object_init_ex(shutdown_function.arguments, &zai_interceptor_bailout_ce);
    Z_OBJ_P(shutdown_function.arguments)->handlers = &zai_interceptor_bailout_handlers;
    register_user_shutdown_function(ZEND_STRL("_dd_bailout_handler"), &shutdown_function);
}

void zai_interceptor_deactivate() {
    zend_hash_destroy(&zai_hook_memory);
}

void zai_interceptor_shutdown_resolving(void);
void zai_interceptor_shutdown() {
    zend_set_user_opcode_handler(ZEND_EXT_NOP, NULL);
    zend_set_user_opcode_handler(ZEND_RETURN, NULL);
    zend_set_user_opcode_handler(ZEND_RETURN_BY_REF, NULL);
    zend_set_user_opcode_handler(ZEND_GENERATOR_RETURN, NULL);
    zend_set_user_opcode_handler(ZEND_HANDLE_EXCEPTION, NULL);
    zend_set_user_opcode_handler(ZEND_FAST_RET, NULL);
    zend_set_user_opcode_handler(ZEND_YIELD, NULL);
    zend_set_user_opcode_handler(ZEND_YIELD_FROM, NULL);
    zend_set_user_opcode_handler(ZAI_INTERCEPTOR_GENERATOR_RESUMPTION_OP, NULL);
#if PHP_VERSION_ID >= 70100
    zend_set_user_opcode_handler(ZAI_INTERCEPTOR_POST_GENERATOR_CREATE_OP, NULL);
    zend_set_user_opcode_handler(ZEND_GENERATOR_CREATE, NULL);
#endif

    zai_interceptor_shutdown_resolving();
}
