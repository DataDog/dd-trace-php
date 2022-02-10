#include "interceptor.h"
#include "../../hook/hook.h"
#include "../../hook/table.h"
#include "zend_vm.h"
#include <ext/standard/basic_functions.h>
#include <Zend/zend_generators.h>
#include <pthread.h>

#if PHP_VERSION_ID < 70100
static ZEND_FUNCTION(pass)
{
}

static const zend_internal_function zend_pass_function = {
        ZEND_INTERNAL_FUNCTION, /* type              */
        {0, 0, 0},              /* arg_flags         */
        0,                      /* fn_flags          */
        NULL,                   /* name              */
        NULL,                   /* scope             */
        NULL,                   /* prototype         */
        0,                      /* num_args          */
        0,                      /* required_num_args */
        NULL,                   /* arg_info          */
        ZEND_FN(pass),          /* handler           */
        NULL                    /* module            */
};
#endif

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
    if (!(CG(compiler_options) & ZEND_COMPILE_EXTENDED_INFO)) {
        if (op_array->last == 0 || op_array->opcodes[0].opcode != ZEND_EXT_NOP) {
            zai_set_ext_nop(&op_array->opcodes[op_array->last++]);
        }
    }
}

static inline bool zai_is_func_recv_opcode(zend_uchar opcode) {
    return opcode == ZEND_RECV || opcode == ZEND_RECV_INIT || opcode == ZEND_RECV_VARIADIC;
}

void zai_interceptor_op_array_pass_two(zend_op_array *op_array) {
    // technically not necessary, but we do it as to not hinder the default optimization of skipping the first RECV ops
    if (!(CG(compiler_options) & ZEND_COMPILE_EXTENDED_INFO)) {
        zend_op *opcodes = op_array->opcodes;
        if (op_array->last > 0 && opcodes[0].opcode == ZEND_EXT_NOP && opcodes[0].extended_value == ZAI_INTERCEPTOR_CUSTOM_EXT) {
            int i = 1;
            while (zai_is_func_recv_opcode(opcodes[i].opcode)) {
                ++i;
            }
            if (i > 1) {
                memmove(&opcodes[0], &opcodes[1], (i - 1) * sizeof(zend_op));
                zai_set_ext_nop(&opcodes[i]);
            }
        }
    }
}

static user_opcode_handler_t prev_ext_nop_handler;
static inline int zai_interceptor_ext_nop_handler_no_prev(zend_execute_data *execute_data) {
    HashTable *hooks;
    if (UNEXPECTED(zai_hook_resolved_table_find((zend_ulong)execute_data->func->op_array.opcodes, &hooks))) {
        zai_interceptor_frame_memory frame_memory, *tmp;
        // do not execute a hook twice
        if (!zai_hook_memory_table_find(execute_data, &tmp)) {
            if (zai_hook_continue(execute_data, &frame_memory.hook_data)) {
                frame_memory.execute_data = execute_data;
                zai_hook_memory_table_insert(execute_data, &frame_memory);
            }
        }
    }

    return ZEND_USER_OPCODE_DISPATCH;
}

static int zai_interceptor_ext_nop_handler(zend_execute_data *execute_data) {
    zai_interceptor_ext_nop_handler_no_prev(execute_data);
    return prev_ext_nop_handler(execute_data);
}

static inline void zai_interceptor_return_impl(zend_execute_data *execute_data) {
    zai_interceptor_frame_memory *frame_memory;
    if (zai_hook_memory_table_find(execute_data, &frame_memory)) {
        zval rv;
        zval *retval = NULL;
        switch (EX(opline)->op1_type) {
            case IS_CONST:
#if PHP_VERSION_ID >= 70300
                retval = RT_CONSTANT(EX(opline), EX(opline)->op1);
#else
                retval = EX_CONSTANT(EX(opline)->op1);
#endif
                break;
            case IS_TMP_VAR:
            case IS_VAR:
            case IS_CV:
                retval = EX_VAR(EX(opline)->op1.var);
                break;
                /* IS_UNUSED is NULL */
        }
        if (!retval || Z_TYPE_INFO_P(retval) == IS_UNDEF) {
            ZVAL_NULL(&rv);
            retval = &rv;
        }

        zai_hook_finish(execute_data, retval, &frame_memory->hook_data);
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
                zval retval;
                ZVAL_NULL(&retval);
                EG(exception) = Z_OBJ_P(fast_call);
                zai_hook_finish(execute_data, &retval, &frame_memory->hook_data);
                EG(exception) = NULL;
                zai_hook_memory_table_del(execute_data);
            }
        }
    }

    return prev_fast_ret_handler ? prev_fast_ret_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static user_opcode_handler_t prev_handle_exception_handler;
static int zai_interceptor_handle_exception_handler(zend_execute_data *execute_data) {
    zai_interceptor_frame_memory *frame_memory;
    if (ZEND_HANDLE_EXCEPTION == EX(opline)->opcode && zai_hook_memory_table_find(execute_data, &frame_memory)) {
        // The catching frame's span will get closed by the return handler so we leave it open
        if (zai_interceptor_is_catching_frame(execute_data, EG(opline_before_exception)) == false) {
            zval retval;
            ZVAL_NULL(&retval);
            zai_hook_finish(execute_data, &retval, &frame_memory->hook_data);
            zai_hook_memory_table_del(execute_data);
        }
    }

    return prev_handle_exception_handler ? prev_handle_exception_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
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
        zai_interceptor_frame_memory *frame_memory;
        zval retval;
        ZVAL_NULL(&retval);
        ZEND_HASH_FOREACH_PTR(&zai_hook_memory, frame_memory) {
            // the individual execute_data contents here may point to bogus (but allocated) memory, but it's just used as key here, hence there's no issue.
            zai_hook_finish(frame_memory->execute_data, &retval, &frame_memory->hook_data);
        } ZEND_HASH_FOREACH_END();
        zend_hash_clean(&zai_hook_memory);
    }
    return SUCCESS;
}

static void (*prev_execute_internal)(zend_execute_data *execute_data, zval *return_value);
static inline void zai_interceptor_execute_internal_impl(zend_execute_data *execute_data, zval *return_value, bool prev) {
    HashTable *hooks;
    zend_function *func = execute_data->func;
    if (UNEXPECTED(zai_hook_resolved_table_find((zend_ulong)func, &hooks))) {
        zai_interceptor_frame_memory frame_memory;
        if (zai_hook_continue(execute_data, &frame_memory.hook_data)) {
            frame_memory.execute_data = execute_data;
            zai_hook_memory_table_insert(execute_data, &frame_memory);
        }

        // we do not use try / catch here as to preserve order of hooks, LIFO style, in bailout handler
        if (prev) {
            prev_execute_internal(execute_data, return_value);
        } else {
            func->internal_function.handler(execute_data, return_value);
        }

        zai_hook_finish(execute_data, return_value, &frame_memory.hook_data);
        zai_hook_memory_table_del(execute_data);
    } else {
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

static zend_object_dtor_obj_t zai_interceptor_generator_dtor_obj;
static void zai_interceptor_generator_dtor_wrapper(zend_object *object) {
    zend_generator *generator = (zend_generator *)object;
    zend_execute_data *execute_data = generator->execute_data;

    zai_interceptor_generator_dtor_obj(object);

    zai_interceptor_frame_memory *frame_memory;
    if (execute_data && zai_hook_memory_table_find(execute_data, &frame_memory)) {
        zval retval;
        ZVAL_NULL(&retval);
        if (!Z_ISUNDEF(generator->retval)) {
            ZVAL_COPY_VALUE(&retval, &generator->retval);
        }
        zai_hook_finish(execute_data, &retval, &frame_memory->hook_data);
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

    zai_interceptor_frame_memory frame_memory;
    if (zai_hook_continue(execute_data, &frame_memory.hook_data)) {
        frame_memory.execute_data = execute_data;
        zai_hook_memory_table_insert(execute_data, &frame_memory);
    }
#endif

    zai_interceptor_generator_handlers = (zend_object_handlers *)generator->std.handlers;
    pthread_once(&zai_interceptor_replace_generator_dtor_once, zai_interceptor_replace_generator_dtor);

    return &generator->std;
}

#if PHP_VERSION_ID >= 70100
// On PHP 7.1+ calling a generator does not directly instantiate a generator, but enters a new execute_data frame, which invokes ZEND_GENERATOR_CREATE
// ZEND_GENERATOR_CREATE in turn then copies the current frame onto a newly allocated chunk of memory, which represents the execute_data for all future operations within that generator.
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
static __thread zend_execute_data zai_interceptor_generator_create_frame;
static int zai_interceptor_post_generator_create_handler(zend_execute_data *execute_data) {
    if (execute_data->opline == &zai_interceptor_generator_create_wrapper[0] || execute_data->opline == &zai_interceptor_generator_create_wrapper[1]) {
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
            zend_execute_data *initial_generator_execute_data = Z_PTR(EX(This));
            zend_execute_data *new_generator_execute_data = ((zend_generator *)Z_OBJ_P(EX(return_value)))->execute_data;
            zai_interceptor_frame_memory *frame_memory;
            if (zai_hook_memory_table_find(initial_generator_execute_data, &frame_memory)) {
                frame_memory->execute_data = new_generator_execute_data;
                zai_hook_memory_table_insert(new_generator_execute_data, frame_memory);
                zai_hook_memory_table_del(initial_generator_execute_data);
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
        zval *retval = EX(return_value);
        if (retval) {
            // ZEND_CALL_TOP means execute_ex (the VM) will return. We don't want to return immediately, but continue
            // execution in our own execuet_data frame. Drop this flag if present and preserve it for later restoring.
            int top_flag = EX_CALL_INFO() & ZEND_CALL_TOP;
            ZEND_SET_CALL_INFO(execute_data, 0, EX_CALL_INFO() & ~ZEND_CALL_TOP);

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
        } else {
            // Never executed generators are handled like immediately destroyed generators
            zval rv;
            ZVAL_NULL(&rv);
            zai_hook_finish(execute_data, &rv, &frame_memory->hook_data);
            zai_hook_memory_table_del(execute_data);
        }
    }

    return prev_generator_create_handler ? prev_generator_create_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}
#endif

void zai_interceptor_setup_resolving_startup(void);
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

    zai_interceptor_setup_resolving_startup();
}

static void zai_hook_memory_dtor(zval *zv) {
    efree(Z_PTR_P(zv));
}

void zai_interceptor_rinit() {
    // install bailout handler - shutdown functions are the first thing we can reliably hook into in case of bailout
    php_shutdown_function_entry shutdown_function = { .arguments = emalloc(sizeof(zval)), .arg_count = 1 };
    object_init_ex(shutdown_function.arguments, &zai_interceptor_bailout_ce);
    Z_OBJ_P(shutdown_function.arguments)->handlers = &zai_interceptor_bailout_handlers;
    register_user_shutdown_function(ZEND_STRL("_dd_bailout_handler"), &shutdown_function);

    zend_hash_init(&zai_hook_memory, 8, nothing, zai_hook_memory_dtor, 0);
}

void zai_interceptor_rshutdown() {
    zend_hash_destroy(&zai_hook_memory);
}
