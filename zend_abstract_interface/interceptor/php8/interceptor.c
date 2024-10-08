#include "../../tsrmls_cache.h"
#include <Zend/zend_observer.h>
#include <hook/hook.h>
#include <hook/table.h>
#include <Zend/zend_attributes.h>
#include <Zend/zend_extensions.h>
#include <Zend/zend_generators.h>
#include "interceptor.h"
#include "zend_vm.h"
#include "zend_closures.h"

#ifdef __SANITIZE_ADDRESS__
# include <sanitizer/common_interface_defs.h>
#endif

#if PHP_VERSION_ID < 80400
int zai_registered_observers = 0;
#endif

void (*zai_interceptor_included_file)(zend_op_array *op_array);

typedef struct {
    zai_hook_memory_t hook_data;
    zend_execute_data *ex;
    bool resumed;
    bool implicit;
} zai_frame_memory;

ZEND_TLS HashTable zai_interceptor_implicit_generators;
ZEND_TLS HashTable zai_hook_memory;
// execute_data is 16 byte aligned (except when it isn't, but it doesn't matter as zend_execute_data is big enough
// our goal is to reduce conflicts
static inline bool zai_hook_memory_table_insert(zend_execute_data *index, zai_frame_memory *inserting) {
    void *inserted;
    return zai_hook_table_insert_at(&zai_hook_memory, ((zend_ulong)index) >> 4, inserting, sizeof(*inserting), &inserted);
}

static inline bool zai_hook_memory_table_find(zend_execute_data *index, zai_frame_memory **found) {
    return zai_hook_table_find(&zai_hook_memory, ((zend_ulong)index) >> 4, (void **)found);
}

static inline bool zai_hook_memory_table_del(zend_execute_data *index) {
    return zend_hash_index_del(&zai_hook_memory, ((zend_ulong)index) >> 4);
}

#if defined(__x86_64__) || defined(__aarch64__)
# if defined(__GNUC__) && !defined(__clang__)
__attribute__((no_sanitize_address))
# endif
static void zai_hook_safe_finish(zend_execute_data *execute_data, zval *retval, zai_frame_memory *frame_memory) {
    if (!CG(unclean_shutdown)) {
        zai_hook_finish(execute_data, retval, &frame_memory->hook_data);
        return;
    }

#ifdef __SANITIZE_ADDRESS__
    const void *bottom;
    size_t capacity;
#endif

    // executing code may write to the stack as normal part of its execution.
    // Jump onto a temporary stack here... to avoid messing with stack allocated data
    JMP_BUF target;
    const size_t stack_size = 1 << 17;
    const size_t stack_top_offset = 0x400;
    void *volatile stack = malloc(stack_size);
    if (SETJMP(target) == 0) {
        void *stacktop = stack + stack_size;
#if PHP_VERSION_ID >= 80300
        register
#endif
        void *stacktarget = stacktop - stack_top_offset;

#ifdef __SANITIZE_ADDRESS__
        void *volatile fake_stack;
        __sanitizer_start_switch_fiber((void**) &fake_stack, stacktop, stack_size);
#define STACK_REG "5"
#else
#define STACK_REG "4"
#endif

        register zend_execute_data *ex = execute_data;
        register zval *rv = retval;
        register zai_hook_memory_t *hook_data = &frame_memory->hook_data;
        register JMP_BUF *jump_target = &target;

        // Add values as register inputs so that compilers are forced to not reorder the variable read below the stack switch
        __asm__ volatile(
#if defined(__x86_64__)
            "mov %" STACK_REG ", %%rsp"
#elif defined(__aarch64__)
#ifdef __SANITIZE_ADDRESS__
            "ldr x7, [sp, #72]\n\t" // magic, but I have no idea what else to do here
#endif
            "mov sp, %" STACK_REG "\n\t"
#ifdef __SANITIZE_ADDRESS__
            "str x7, [sp, #72]"
#endif
#endif
            : "+r"(ex), "+r"(rv), "+r"(hook_data), "+r"(jump_target)
#ifdef __SANITIZE_ADDRESS__
                , "+r"(fake_stack)
#endif
            : "r"(stacktarget)
#if defined(__SANITIZE_ADDRESS__) && defined(__aarch64__)
            : "x7"
#endif
            );

#ifdef __SANITIZE_ADDRESS__
        __sanitizer_finish_switch_fiber(fake_stack, &bottom, &capacity);
#endif

#if PHP_VERSION_ID >= 80300
        void *stack_base = EG(stack_base);
        void *stack_limit = EG(stack_limit);

        EG(stack_base) = stacktarget;
        EG(stack_limit) = (void*)((uintptr_t)stacktarget - stack_top_offset
#ifdef ZEND_CHECK_STACK_LIMIT
            - EG(reserved_stack_size) * 2
#endif
        );
#endif

        zai_hook_finish(ex, rv, hook_data);

#if PHP_VERSION_ID >= 80300
        EG(stack_base) = stack_base;
        EG(stack_limit) = stack_limit;
#endif

#ifdef __SANITIZE_ADDRESS__
        __sanitizer_start_switch_fiber(NULL, bottom, capacity);
#endif

        LONGJMP(*jump_target, 1);
    }

#ifdef __SANITIZE_ADDRESS__
    __sanitizer_finish_switch_fiber(NULL, &bottom, &capacity);
#endif

    free(stack);
}
#else
static inline void zai_hook_safe_finish(zend_execute_data *execute_data, zval *retval, zai_frame_memory *frame_memory) {
    zai_hook_finish(execute_data, retval, &frame_memory->hook_data);
}
#endif

static void zai_interceptor_observer_begin_handler(zend_execute_data *execute_data) {
    zai_frame_memory frame_memory;
    if (zai_hook_continue(execute_data, &frame_memory.hook_data) == ZAI_HOOK_CONTINUED) {
        frame_memory.ex = execute_data;
        zai_hook_memory_table_insert(execute_data, &frame_memory);
    }
}

static void zai_interceptor_observer_end_handler(zend_execute_data *execute_data, zval *retval) {
    zai_frame_memory *frame_memory;
    if (zai_hook_memory_table_find(execute_data, &frame_memory)) {
        if (!retval) {
            retval = &EG(uninitialized_zval);
        }
        zai_hook_safe_finish(execute_data, retval, frame_memory);
        zai_hook_memory_table_del(execute_data);
    }
}

// Note: This is not optimized. I.e. a scenario where an observed generator yields from other generators which do a recursive yield from,
//       every single yield in that generator will have an O(nested generators) performance.
// In real world I've yet to see excessive recursion of generators, but here is room for potential future optimizations
static void zai_interceptor_generator_yielded(zend_execute_data *ex, zval *key, zval *yielded, zai_frame_memory *frame_memory) {
    zend_generator *generator = (zend_generator *)ex->return_value, *leaf = generator->node.ptr.leaf;
    // yields happen inside out
    do {
        if (!frame_memory->implicit) {
            frame_memory->resumed = false;
            zai_hook_generator_yielded(generator->execute_data, key, yielded, &frame_memory->hook_data);
        }

        if (generator->node.children == 0) {
            break;
        }
        if (generator->node.children == 1) {
#if PHP_VERSION_ID >= 80100
            generator = generator->node.child.single;
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
            generator = leaf;
        }
    } while (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory));
}

static void zai_interceptor_generator_resumption(zend_execute_data *ex, zval *sent, zai_frame_memory *frame_memory) {
    zend_generator *generator = (zend_generator *)ex->return_value;
    if (generator->node.ptr.leaf) {
        generator = generator->node.ptr.leaf;
    }
    // resumptions occur from outside to inside
    do {
        if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory) && !frame_memory->implicit && !frame_memory->resumed) {
            frame_memory->resumed = true;
            zai_hook_generator_resumption(generator->execute_data, sent, &frame_memory->hook_data);
        }
    } while ((generator = generator->node.parent));
}

static void zai_interceptor_observer_generator_resumption_handler(zend_execute_data *execute_data) {
    zend_generator *generator = (zend_generator *)execute_data->return_value;

    zai_frame_memory *frame_memory;
    if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory)) {
        zval *received = !EG(exception) && generator->send_target ? generator->send_target : &EG(uninitialized_zval);
        zai_interceptor_generator_resumption(execute_data, received, frame_memory);
    }
}

typedef struct {
    zend_object_iterator it;
    union {
        zend_array *array;
        zend_object_iterator *iterator;
        zval zv;
    };
    zend_generator *generator;
    zai_frame_memory *frame_memory;
    zval last_value;
} zai_interceptor_iterator_wrapper;

static void zai_interceptor_iterator_wrapper_dtor(zend_object_iterator *it) {
    zval_ptr_dtor(&((zai_interceptor_iterator_wrapper *)it)->zv);
}

static HashTable *zai_interceptor_iterator_wrapper_get_gc(zend_object_iterator *it, zval **table, int *n) {
    *table = &((zai_interceptor_iterator_wrapper *)it)->zv;
    *n = 1;
    return NULL;
}

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

static const zend_object_iterator_funcs zai_interceptor_iterator_wrapper_iterator_funcs = {
    .dtor = zai_interceptor_iterator_wrapper_dtor,
    .get_gc = zai_interceptor_iterator_wrapper_get_gc,
    .valid = zai_interceptor_iterator_wrapper_iterator_valid,
    .get_current_data = zai_interceptor_iterator_wrapper_iterator_get_current_data,
    .get_current_key = zai_interceptor_iterator_wrapper_iterator_get_current_key,
    .move_forward = zai_interceptor_iterator_wrapper_iterator_move_forward,
    .invalidate_current = NULL,
    .rewind = NULL,
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

static const zend_object_iterator_funcs zai_interceptor_iterator_wrapper_array_funcs = {
    .dtor = zai_interceptor_iterator_wrapper_dtor,
    .get_gc = zai_interceptor_iterator_wrapper_get_gc,
    .valid = zai_interceptor_iterator_wrapper_array_valid,
    .get_current_data = zai_interceptor_iterator_wrapper_array_get_current_data,
    .get_current_key = zai_interceptor_iterator_wrapper_array_get_current_key,
    .move_forward = zai_interceptor_iterator_wrapper_array_move_forward,
    .invalidate_current = NULL,
    .rewind = NULL,
};

// This function MUST NOT be called with remove = false if there is already an observer installed
// It also MUST NOT be called with remove = true if there is no observer installed yet
#if PHP_VERSION_ID < 80200
void (*zai_interceptor_replace_observer)(zend_function *func, bool remove, zend_observer_fcall_end_handler *next_end_handler);
#else
void zai_interceptor_replace_observer(zend_function *func, bool remove, zend_observer_fcall_end_handler *next_end_handler);
#endif

#if PHP_VERSION_ID < 80400
#define ZAI_GENERATOR_YIELD_OFFSET (-1)
#else
#define ZAI_GENERATOR_YIELD_OFFSET 0
#endif
static void zai_interceptor_observer_generator_yield(zend_execute_data *ex, zval *retval, zend_generator *generator, zai_frame_memory *frame_memory) {
    if (generator->execute_data && generator->execute_data->opline[ZAI_GENERATOR_YIELD_OFFSET].opcode == ZEND_YIELD_FROM) {
        // There are two cases here:
        // a) yield from array or iterator
        //    Here we can just wrap the iterator or array into our custom iterator, transparently without observable side effects
        // b) yield from generator
        //    It is not possible to just wrap a generator, because using ->send() or ->throw() would not forward the values up in the generator chain;
        //    even worse, using ->throw() stops an iterator completely. Thus our only choice is instrumenting the yielded from generator directly.
        if (Z_ISUNDEF(generator->values)) {
            // a yielded from generator needs begin/end handlers to track yields etc.
            // it needs to keep track which parent generators are yielding from
            // as opposed to PHP itself, which is only interested in the active leaf and the current root
            // we need to observe if current generator is observed.
            // it is enough to check whether the parent is observed, otherwise we can abort.
            // if we encounter a non-observed generator, we must mark all instances of that generators yield from chain as observed
            zend_generator *root = zend_generator_get_current(generator);
            if (!Z_ISUNDEF(root->value)) {
                zai_interceptor_generator_yielded(ex, &root->key, &root->value, frame_memory);
            }

            generator = generator->node.parent;
            zai_install_address genaddr = zai_hook_install_address_user(&generator->execute_data->func->op_array);
            while (!zend_hash_index_exists(&zai_hook_resolved, genaddr)) {
                zval *count = zend_hash_index_find(&zai_interceptor_implicit_generators, genaddr);
                if (!count) {
                    zai_interceptor_replace_observer(generator->execute_data->func, false, NULL);

                    zval one;
                    ZVAL_LONG(&one, 1);
                    zend_hash_index_add(&zai_interceptor_implicit_generators, genaddr, &one);
                } else if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory)) {
                    break;
                } else {
                    ++Z_LVAL_P(count);
                }
                zai_frame_memory generator_memory;
                generator_memory.implicit = true;
                generator_memory.resumed = false;
                generator_memory.ex = generator->execute_data;
                zai_hook_memory_table_insert((zend_execute_data *)generator, &generator_memory);
                generator = generator->node.parent;
                if (!generator) {
                    break;
                }
                genaddr = zai_hook_install_address_user(&generator->execute_data->func->op_array);
            }
        } else {
            zai_interceptor_iterator_wrapper *it = ecalloc(1, sizeof(*it));
            it->generator = generator;
            it->frame_memory = frame_memory;

            ZVAL_COPY_VALUE(&it->zv, &generator->values);
            if (Z_TYPE(generator->values) == IS_ARRAY) {
                it->it.funcs = &zai_interceptor_iterator_wrapper_array_funcs;
            } else {
                it->it.funcs = &zai_interceptor_iterator_wrapper_iterator_funcs;
            }

            zend_iterator_init(&it->it);
            ZVAL_OBJ(&generator->values, &it->it.std);
        }
    } else {
        zai_interceptor_generator_yielded(ex, &generator->key, retval, frame_memory);
    }
}

static void zai_interceptor_handle_ended_generator(zend_generator *generator, zend_execute_data *ex, zval *retval, zai_frame_memory *frame_memory) {
    if (frame_memory->implicit) {
        zai_install_address genaddr = zai_hook_install_address_user(&generator->execute_data->func->op_array);
        zval *count = zend_hash_index_find(&zai_interceptor_implicit_generators, genaddr);
        if (count && !--Z_LVAL_P(count)) {
            zend_hash_index_del(&zai_interceptor_implicit_generators, genaddr);
            if (!zend_hash_index_exists(&zai_hook_resolved, genaddr)) {
                zend_observer_fcall_end_handler next_end_handler = NULL;
                zai_interceptor_replace_observer(generator->execute_data->func, true, &next_end_handler);
                if (UNEXPECTED(next_end_handler)) {
                    next_end_handler(ex, retval);
                }
            }
        }
    } else {
        zai_hook_safe_finish(ex, retval, frame_memory);
    }
    zai_hook_memory_table_del((zend_execute_data *)generator);
}

static void zai_interceptor_observer_generator_end_handler(zend_execute_data *execute_data, zval *retval) {
    zend_generator *generator = (zend_generator *)execute_data->return_value;

    zai_frame_memory *frame_memory;
    if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory)) {
        if (!EG(exception) && Z_ISUNDEF(generator->retval)) {
            zai_interceptor_observer_generator_yield(execute_data, retval, generator, frame_memory);
        } else {
            if (!retval) {
                retval = &EG(uninitialized_zval);
            }
            zai_interceptor_handle_ended_generator(generator, execute_data, retval, frame_memory);
        }
    }
}

static inline zend_observer_fcall_handlers zai_interceptor_determine_handlers(zend_function *func) {
    if (func->common.fn_flags & ZEND_ACC_GENERATOR) {
        return (zend_observer_fcall_handlers){zai_interceptor_observer_generator_resumption_handler, zai_interceptor_observer_generator_end_handler};
    }
    return (zend_observer_fcall_handlers){zai_interceptor_observer_begin_handler, zai_interceptor_observer_end_handler};
}

#if PHP_VERSION_ID < 80200
#define ZEND_OBSERVER_DATA(function) \
    ZEND_OP_ARRAY_EXTENSION((&(function)->op_array), zend_observer_fcall_op_array_extension)
#elif PHP_VERSION_ID < 80400
#define ZEND_OBSERVER_DATA(function) \
    ZEND_OP_ARRAY_EXTENSION((&(function)->common), zend_observer_fcall_op_array_extension)
#endif

#if PHP_VERSION_ID < 80400
#define ZEND_OBSERVER_NOT_OBSERVED ((void *) 2)

#if PHP_VERSION_ID < 80200
static inline bool zai_interceptor_is_attribute_ctor(zend_function *func) {
    zend_class_entry *ce = func->common.scope;
    return ce && UNEXPECTED(ce->attributes) && UNEXPECTED(zend_get_attribute_str(ce->attributes, ZEND_STRL("attribute")) != NULL)
        && zend_string_equals_literal_ci(func->common.function_name, "__construct");
}

static void zai_interceptor_observer_begin_handler_attribute_ctor(zend_execute_data *execute_data) {
    // On PHP 8.1.2 and prior, there exists a bug (see https://github.com/php/php-src/pull/7885).
    // It causes the dummy frame of observers to not be skipped, which has no run_time_cache and thereby crashes, if unwound across.
    // Adding the ZEND_ACC_CALL_VIA_TRAMPOLINE flag causes the frame to be always skipped on observer_end unwind
    if (execute_data->prev_execute_data && !ZEND_MAP_PTR(execute_data->prev_execute_data->func->op_array.run_time_cache)) {
        execute_data->prev_execute_data->func->common.fn_flags |= ZEND_ACC_CALL_VIA_TRAMPOLINE;
    }
    zai_interceptor_observer_begin_handler(execute_data);
}

typedef struct {
    // points after the last handler
    zend_observer_fcall_handlers *end;
    // a variadic array using "struct hack"
    zend_observer_fcall_handlers handlers[1];
} zend_observer_fcall_data;

void zai_interceptor_replace_observer_legacy(zend_function *func, bool remove, zend_observer_fcall_end_handler *next_end_handler) {
    (void)next_end_handler;

    zend_op_array *op_array = &func->op_array;
    if (!RUN_TIME_CACHE(op_array)) {
        return;
    }

    if ((op_array->fn_flags & ZEND_ACC_GENERATOR) && zend_hash_index_find(&zai_interceptor_implicit_generators, zai_hook_install_address_user(op_array))) {
        return;
    }

    zend_observer_fcall_data *data = ZEND_OBSERVER_DATA(func);
    if (!data) {
        return;
    }

    // Always observe these for their special handling, to add trampoline flag on parent call
    if (zai_interceptor_is_attribute_ctor(func)) {
        return;
    }

    if (remove) {
        for (zend_observer_fcall_handlers *handlers = data->handlers, *end = data->end; handlers != end; ++handlers) {
            if (handlers->end == zai_interceptor_observer_end_handler || handlers->end == zai_interceptor_observer_generator_end_handler) {
                if (data->handlers == end - 1) {
                    data->end = data->handlers;
                } else {
                    *handlers = *(end - 1);
                    data->end = end - 1;
                }
                break;
            }
        }
    } else {
        // We have space allocated...
        *(data->end++) = zai_interceptor_determine_handlers(func);
    }
}

#define zai_interceptor_replace_observer zai_interceptor_replace_observer_current

// Allocate some space. This space can be used to install observers afterwards
// ... I would love to make use of ZEND_OBSERVER_NOT_OBSERVED optimization, but this does not seem possible :-(
static void zai_interceptor_observer_placeholder_handler(zend_execute_data *execute_data) {
    zend_observer_fcall_data *data = ZEND_OBSERVER_DATA(execute_data->func);
    for (zend_observer_fcall_handlers *handlers = data->handlers, *end = data->end; handlers != end; ++handlers) {
        if (handlers->begin == zai_interceptor_observer_placeholder_handler) {
            data->end = end - 1;
            if (handlers == end - 1) {
                handlers->begin = NULL;
            } else {
                *handlers = *(end - 1);
                handlers->begin(execute_data);
            }
            break;
        }
    }
}
#endif

void zai_interceptor_replace_observer(zend_function *func, bool remove, zend_observer_fcall_end_handler *next_end_handler) {
#if PHP_VERSION_ID < 80200
    if (!ZEND_MAP_PTR(func->op_array.run_time_cache) || !RUN_TIME_CACHE(&func->op_array) || (func->common.fn_flags & ZEND_ACC_HEAP_RT_CACHE) != 0) {
#else
    if (!ZEND_MAP_PTR(func->common.run_time_cache) || !RUN_TIME_CACHE(&func->common) || !ZEND_OBSERVER_DATA(func) || (func->common.fn_flags & ZEND_ACC_HEAP_RT_CACHE) != 0) {
#endif
        return;
    }

    if (func->common.fn_flags & ZEND_ACC_GENERATOR) {
        if (zend_hash_index_find(&zai_interceptor_implicit_generators, zai_hook_install_address_user(&func->op_array))) {
            return;
        }
    }

    zend_observer_fcall_begin_handler *beginHandler = (void *)&ZEND_OBSERVER_DATA(func), *beginEnd = beginHandler + zai_registered_observers - 1;
    zend_observer_fcall_end_handler *endHandler = (zend_observer_fcall_end_handler *)beginEnd + 1, *endEnd = endHandler + zai_registered_observers - 1;

    if (remove) {
        for (zend_observer_fcall_begin_handler *curHandler = beginHandler; curHandler <= beginEnd; ++curHandler) {
            if (*curHandler == zai_interceptor_observer_begin_handler || *curHandler == zai_interceptor_observer_generator_resumption_handler) {
                if (zai_registered_observers == 1 || (curHandler == beginHandler && curHandler[1] == NULL)) {
                    *curHandler = ZEND_OBSERVER_NOT_OBSERVED;
                } else {
                    if (curHandler != beginEnd) {
                        memmove(curHandler, curHandler + 1, sizeof(curHandler) * (beginEnd - curHandler));
                    }
                    *beginEnd = NULL;
                }
                break;
            }
        }

        for (zend_observer_fcall_end_handler *curHandler = endHandler; curHandler <= endEnd; ++curHandler) {
            if (*curHandler == zai_interceptor_observer_end_handler || *curHandler == zai_interceptor_observer_generator_end_handler) {
                if (zai_registered_observers == 1 || (curHandler == endHandler && *(curHandler + 1) == NULL)) {
                    *curHandler = ZEND_OBSERVER_NOT_OBSERVED;
                } else {
                    if (curHandler != endEnd) {
                        memmove(curHandler, curHandler + 1, sizeof(curHandler) * (endEnd - curHandler));
                        *next_end_handler = *curHandler; // the handler which is now at the place from where we removed ours
                    }
                    *endEnd = NULL;
                }
                break;
            }
        }
    } else {
        // preserve the invariant that end handlers are in reverse order of begin handlers
        zend_observer_fcall_handlers handlers = zai_interceptor_determine_handlers(func);
        if (handlers.begin) {
            if (*beginHandler == ZEND_OBSERVER_NOT_OBSERVED) {
                *beginHandler = handlers.begin;
            } else {
                for (zend_observer_fcall_begin_handler *curHandler = beginHandler + 1; curHandler <= beginEnd; ++curHandler) {
                    if (*curHandler == NULL) {
                        *curHandler = handlers.begin;
                        break;
                    }
                }
            }
        }
        if (*endHandler != ZEND_OBSERVER_NOT_OBSERVED) {
            memmove(endHandler + 1, endHandler, sizeof(endHandler) * (endEnd - endHandler));
        }
        *endHandler = handlers.end;
    }
}
#else
void zai_interceptor_replace_observer(zend_function *func, bool remove, zend_observer_fcall_end_handler *next_end_handler) {
    if (!ZEND_MAP_PTR(func->op_array.run_time_cache) || !RUN_TIME_CACHE(&func->common) || !*ZEND_OBSERVER_DATA(func) || (func->common.fn_flags & ZEND_ACC_HEAP_RT_CACHE) != 0) {
        return;
    }

    if (func->common.fn_flags & ZEND_ACC_GENERATOR) {
        if (zend_hash_index_find(&zai_interceptor_implicit_generators, zai_hook_install_address_user(&func->op_array))) {
            return;
        }
    }

    zend_observer_fcall_handlers handlers = zai_interceptor_determine_handlers(func);
    if (remove) {
        zend_observer_fcall_begin_handler next_begin;
        zend_observer_remove_begin_handler(func, handlers.begin, &next_begin);
        zend_observer_remove_end_handler(func, handlers.end, next_end_handler);
    } else {
        zend_observer_add_begin_handler(func, handlers.begin);
        zend_observer_add_end_handler(func, handlers.end);
    }
}
#endif

static zend_always_inline bool zai_interceptor_shall_install_handlers(zend_function *func) {
    // We opt to always install observers for runtime op_arrays with dynamic runtime cache
    // Reason: we cannot find them reliably and inexpensively at runtime (e.g. dynamic closures) when observers change
    if (UNEXPECTED((func->common.fn_flags & ZEND_ACC_HEAP_RT_CACHE) != 0)) {
        // Note that only run-time constructs like Closures and top-level code (which we ignore) are HEAP_RT_CACHE.
        // Given that closures are always hooked at runtime only, there's no need for runtime resolving
        return true;
    }

#if PHP_VERSION_ID < 80200
    if (zai_hook_installed_user(&func->op_array)) {
#else
    if (zai_hook_installed_func(func)) {
#endif
        return true;
    }

    if (func->common.fn_flags & ZEND_ACC_GENERATOR) {
        if (zend_hash_index_exists(&zai_interceptor_implicit_generators, zai_hook_install_address_user(&func->op_array))) {
            return true;
        }
    }
    return false;
}

static zend_observer_fcall_handlers zai_interceptor_observer_fcall_init(zend_execute_data *execute_data) {
    zend_function *func = execute_data->func;
#if PHP_VERSION_ID < 80200
    #undef zai_interceptor_replace_observer

    // short-circuit this, it only happens on old versions, avoid checking overhead if unnecessary
    if (zai_interceptor_replace_observer != zai_interceptor_replace_observer_current && zai_interceptor_is_attribute_ctor(func)) {
        return (zend_observer_fcall_handlers){zai_interceptor_observer_begin_handler_attribute_ctor, zai_interceptor_observer_end_handler};
    }
#endif

    if (UNEXPECTED(zai_interceptor_shall_install_handlers(func))) {
        return zai_interceptor_determine_handlers(func);
    }

#if PHP_VERSION_ID < 80200
    // Use one-time begin handler which will remove itself
    return (zend_observer_fcall_handlers){zai_interceptor_replace_observer == zai_interceptor_replace_observer_current ? NULL : zai_interceptor_observer_placeholder_handler, NULL};
#else
    return (zend_observer_fcall_handlers){NULL, NULL};
#endif
}

static const zend_op zai_interceptor_generator_post_op_template = {
    .opcode = ZEND_RETURN,
    .op1 = { .var = XtOffsetOf(zend_execute_data, This) },
    .op1_type = IS_TMP_VAR,
    .op2 = { .num = 0 },
    .op2_type = IS_UNUSED,
    .result = { .num = 0 },
    .result_type = IS_UNUSED,
    .lineno = -1,
    .extended_value = 0,
};

static zend_op zai_interceptor_generator_post_op[3];

ZEND_TLS zend_execute_data *zai_interceptor_prev_execute_data;
ZEND_TLS uint32_t zai_interceptor_prev_call_info;
ZEND_TLS zval *zai_interceptor_prev_stack_top;

#ifndef Z_TYPE_EXTRA
#define Z_TYPE_EXTRA(zval)			(zval).u1.v.u.extra
#endif

// Copied from zend_vm_execute.h: Make sure we don't mess with JIT/VM internal state, and do back these up
# if defined(__GNUC__) && defined(__x86_64__)
#  define HYBRID_JIT_GUARD() __asm__ __volatile__ (""::: "rbx","r12","r13","r14","r15")
# elif defined(__GNUC__) && defined(__aarch64__)
#  define HYBRID_JIT_GUARD() __asm__ __volatile__ (""::: "x19","x20","x21","x22","x23","x24","x25","x26","x27","x28")
# else
#  define HYBRID_JIT_GUARD()
# endif
static zend_never_inline const void *zai_interceptor_handle_created_generator_func(void) {
    HYBRID_JIT_GUARD();
    zai_frame_memory frame_memory;
    zend_execute_data *execute_data = EG(current_execute_data);
    EX(prev_execute_data) = zai_interceptor_prev_execute_data; // fixup stacktrace

    // put stuff back
    EX_CALL_INFO() = zai_interceptor_prev_call_info;
    EG(vm_stack_top) = zai_interceptor_prev_stack_top;

    zend_object *generator = Z_OBJ_P(EX(return_value)); // save it here; EX(return_value) might be updated in zai_hook_continue
    if (zai_hook_continue(execute_data, &frame_memory.hook_data) == ZAI_HOOK_CONTINUED) {
        frame_memory.resumed = false;
        frame_memory.implicit = false;
        frame_memory.ex = execute_data;
        zai_hook_memory_table_insert((zend_execute_data *) generator, &frame_memory);
    }
    EG(current_execute_data) = execute_data;

    // We'll copy from EX(This) in ZEND_RETURN then (we don't need EX(This) anymore from this moment on)
    ZVAL_COPY_VALUE(&EX(This), EX(return_value));
    Z_TYPE_EXTRA(EX(This)) = (zai_interceptor_prev_call_info & ~ZEND_CALL_RELEASE_THIS) >> 16;

    // Now execute a "real" return opcode, which is in control of the VM and can update execute_data and opline.
    ++EX(opline);
    return zai_interceptor_generator_post_op[2].handler;
}

#ifdef __GNUC__
bool zai_interceptor_avoid_compile_opt = true;
uintptr_t zai_interceptor_dummy_label_use;

// a bit of stuff to make the function control flow undecidable for the compiler, so that it doesn't optimize anything away
static void *ZEND_FASTCALL zai_interceptor_handle_created_generator_goto(void) {
    if (zai_interceptor_avoid_compile_opt) {
        uintptr_t tmp = (uintptr_t)&&zai_interceptor_handle_created_generator_goto_LABEL2;
        zai_interceptor_dummy_label_use = tmp;
        zai_interceptor_avoid_compile_opt = false; // tell the compiler that the other branch is not unreachable
        // We need to return zai_interceptor_handle_created_generator_goto_LABEL; zai_interceptor_handle_created_generator_goto cannot be jumped to directly as it will contain prologue updating the stack pointer.
        tmp = (uintptr_t)&&zai_interceptor_handle_created_generator_goto_LABEL;
        return (void *)tmp; // extra var to prevent 'function returns address of label [-Werror=return-local-addr]'
    }
    zai_interceptor_handle_created_generator_goto_LABEL:
    goto *(void**)zai_interceptor_handle_created_generator_func();
    zai_interceptor_handle_created_generator_goto_LABEL2:
    return (void *)zai_interceptor_dummy_label_use;
}
#endif

// Windows & Mac use call VM without IP/FP
static int ZEND_FASTCALL zai_interceptor_handle_created_generator_call(void) {
    zai_interceptor_handle_created_generator_func();
    return 0 /* ZEND_VM_CONTINUE */;
}

static zend_object *(*generator_create_prev)(zend_class_entry *class_type);
static zend_object *zai_interceptor_generator_create(zend_class_entry *class_type) {
    zend_generator *generator = (zend_generator *)generator_create_prev(class_type);

    zend_execute_data *execute_data = EG(current_execute_data);
    // We also land here when new Generator is invoked. We only care about ZEND_GENERATOR_CREATE.
    if (execute_data && execute_data->func
     && (execute_data->func->common.fn_flags & ZEND_ACC_GENERATOR)
     && execute_data->opline->opcode == ZEND_GENERATOR_CREATE) {
        if (zai_hook_installed_user(&execute_data->func->op_array)) {
            EX(opline) = zai_interceptor_generator_post_op; // will be advanced to [1] immediately
            zai_interceptor_prev_call_info = EX_CALL_INFO();
            // Prevent freeing the frame (it will reset EG(vm_stack_top) though, so we need to back it up)
            EX_CALL_INFO() &= ~(ZEND_CALL_TOP|ZEND_CALL_ALLOCATED);
            zai_interceptor_prev_execute_data = EX(prev_execute_data);
            EX(prev_execute_data) = execute_data;
            zai_interceptor_prev_stack_top = EG(vm_stack_top);

            // Now that we're persisting the stack frame a bit longer and are going to free args in ZEND_RETURN later, we have to incref them here
            for (zval *var = EX_VAR_NUM(0), *end = var + EX(func)->op_array.last_var; var < end; ++var) {
                Z_TRY_ADDREF_P(var);
            }
            if (zai_interceptor_prev_call_info & ZEND_CALL_FREE_EXTRA_ARGS) {
                for (zval *var = EX_VAR_NUM(EX(func)->op_array.last_var + EX(func)->op_array.T), *end = var + EX_NUM_ARGS() - EX(func)->op_array.num_args; var < end; ++var) {
                    Z_TRY_ADDREF_P(var);
                }
            }
            if (zai_interceptor_prev_call_info & ZEND_CALL_HAS_EXTRA_NAMED_PARAMS) {
                GC_ADDREF(EX(extra_named_params));
            }
            if (zai_interceptor_prev_call_info & ZEND_CALL_CLOSURE) {
                GC_ADDREF(ZEND_CLOSURE_OBJECT(EX(func)));
            }
        }
    }

    return &generator->std;
}

static zend_object_dtor_obj_t zai_interceptor_generator_dtor_obj;
static void zai_interceptor_generator_dtor_wrapper(zend_object *object) {
    zend_generator *generator = (zend_generator *)object;

    zai_frame_memory *frame_memory;
    if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory)) {
        // generator dtor frees it
        zend_execute_data ex = *generator->execute_data;

        zai_interceptor_generator_dtor_obj(object);

        // may have returned in the dtor, don't execute twice
        if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory)) {
            // aborted generator
            zval retval;
            ZVAL_NULL(&retval);
            zai_interceptor_handle_ended_generator(generator, &ex, &retval, frame_memory);
        }
    } else {
        zai_interceptor_generator_dtor_obj(object);
    }
}

#if PHP_VERSION_ID < 80200
static void (*prev_execute_internal)(zend_execute_data *execute_data, zval *return_value);
static inline void zai_interceptor_execute_internal_impl(zend_execute_data *execute_data, zval *return_value, bool prev, zif_handler handler) {
    zend_function *func = execute_data->func;
    if (UNEXPECTED(zai_hook_installed_internal(&func->internal_function))) {
        zai_frame_memory frame_memory;
        if (zai_hook_continue(execute_data, &frame_memory.hook_data) != ZAI_HOOK_CONTINUED) {
            goto skip;
        }
        frame_memory.ex = execute_data;
        zai_hook_memory_table_insert(execute_data, &frame_memory);

        zend_try {
            if (prev) {
                prev_execute_internal(execute_data, return_value);
            } else {
                handler(execute_data, return_value);
            }
        } zend_catch {
            zend_execute_data *active_execute_data = EG(current_execute_data);

            // We need to ensure order of hooks being preserved
            zai_frame_memory *frame;
            ZEND_HASH_REVERSE_FOREACH_PTR(&zai_hook_memory, frame) {
                // TODO: fibers. We probably need a hashtable _per fiber_?
                zend_execute_data *frame_ex = frame->ex;
                if (!(frame_ex->func->common.fn_flags & ZEND_ACC_GENERATOR)) {
                    // generators are freed separately, upon their normal destruction
                    // avoid confusing the observers: prev_execute_data is set to current_execute_data which otherwise may be NULL in zai symbol calls
                    EG(current_execute_data) = execute_data;
                    zai_hook_safe_finish(execute_data, &EG(uninitialized_zval), frame);
                    zai_hook_memory_table_del(execute_data);

                    if (frame_ex == execute_data) {
                        break;
                    }
                }
            } ZEND_HASH_FOREACH_END();

            EG(current_execute_data) = active_execute_data;
            zend_bailout();
        } zend_end_try()

        zai_hook_finish(execute_data, return_value, &frame_memory.hook_data);
        zai_hook_memory_table_del(execute_data);
    } else {
        skip:
        if (prev) {
            prev_execute_internal(execute_data, return_value);
        } else {
            handler(execute_data, return_value);
        }
    }
}

static void zai_interceptor_execute_internal_no_prev(zend_execute_data *execute_data, zval *return_value) {
    zai_interceptor_execute_internal_impl(execute_data, return_value, false, execute_data->func->internal_function.handler);
}

static void zai_interceptor_execute_internal(zend_execute_data *execute_data, zval *return_value) {
    zai_interceptor_execute_internal_impl(execute_data, return_value, true, NULL);
}

void zai_interceptor_execute_internal_with_handler(INTERNAL_FUNCTION_PARAMETERS, zif_handler handler) {
    zai_frame_memory *frame_memory;
    if (zai_hook_memory_table_find(execute_data, &frame_memory)) {
        handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    } else {
        zai_interceptor_execute_internal_impl(execute_data, return_value, false, handler);
    }
}
#endif

void zai_interceptor_setup_resolving_post_startup(void);

// extension handles are supposed to be frozen at post_startup time and observer extension handle allocation
// incidentally is right before the defacto freeze via zend_finalize_system_id
static zend_result (*prev_post_startup)(void);
zend_result zai_interceptor_post_startup(void) {
    zend_result result = prev_post_startup ? prev_post_startup() : SUCCESS; // first run opcache post_startup, then ours

    zai_hook_post_startup();
    zai_interceptor_setup_resolving_post_startup();
#if PHP_VERSION_ID < 80400
    zai_registered_observers = (zend_op_array_extension_handles - zend_observer_fcall_op_array_extension) / 2;
#endif

#ifdef __GNUC__
    zai_interceptor_avoid_compile_opt = true; // Reset it in case MINIT gets re-executed
#endif

    return result;
}

void zai_interceptor_startup(void) {
#if PHP_VERSION_ID < 80200
#if PHP_VERSION_ID < 80100
#define RUN_TIME_CACHE_OBSERVER_PATCH_VERSION 18
#else
#define RUN_TIME_CACHE_OBSERVER_PATCH_VERSION 4
#endif

    zend_long patch_version = Z_LVAL_P(zend_get_constant_str(ZEND_STRL("PHP_RELEASE_VERSION")));
    if (patch_version < RUN_TIME_CACHE_OBSERVER_PATCH_VERSION) {
        zai_interceptor_replace_observer = zai_interceptor_replace_observer_legacy;
    } else {
        zai_interceptor_replace_observer = zai_interceptor_replace_observer_current;
    }

    prev_execute_internal = zend_execute_internal;
    zend_execute_internal = prev_execute_internal ? zai_interceptor_execute_internal : zai_interceptor_execute_internal_no_prev;
#endif

    zend_observer_fcall_register(zai_interceptor_observer_fcall_init);

    // get hold of a generator object to access handlers
    zend_objects_store objects_store = EG(objects_store);
    zend_object *generator;
    EG(objects_store) = (zend_objects_store){
        .object_buckets = &generator,
        .free_list_head = 0,
        .size = 1,
        .top = 0
    };
    zend_ce_generator->create_object(zend_ce_generator);

    zai_interceptor_generator_dtor_obj = generator->handlers->dtor_obj;
    ((zend_object_handlers *)generator->handlers)->dtor_obj = zai_interceptor_generator_dtor_wrapper;
    generator_create_prev = zend_ce_generator->create_object;
    zend_ce_generator->create_object = zai_interceptor_generator_create;

    efree(generator);
    EG(objects_store) = objects_store;

    zai_interceptor_generator_post_op[0] = zai_interceptor_generator_post_op_template;
    zai_interceptor_generator_post_op[1] = zai_interceptor_generator_post_op_template;
#ifdef __GNUC__
    int kind = zend_vm_kind();
    zai_interceptor_generator_post_op[1].handler = kind == ZEND_VM_KIND_HYBRID || kind == ZEND_VM_KIND_GOTO ? zai_interceptor_handle_created_generator_goto() : (void*)zai_interceptor_handle_created_generator_call;
#else
    zai_interceptor_generator_post_op[1].handler = (void *)zai_interceptor_handle_created_generator_call;
#endif
    // Note: return handler without SPEC(OBSERVER) (will be the case as before post_startup zend_observer_fcall_op_array_extension won't be set yet)
    zai_interceptor_generator_post_op[2] = zai_interceptor_generator_post_op_template;
    zend_vm_set_opcode_handler(&zai_interceptor_generator_post_op[2]);

    prev_post_startup = zend_post_startup_cb;
    zend_post_startup_cb = zai_interceptor_post_startup;

    zai_hook_on_update = zai_interceptor_replace_observer;
}

static void zai_hook_memory_dtor(zval *zv) {
    efree(Z_PTR_P(zv));
}

#if PHP_VERSION_ID < 80200
void zai_interceptor_reset_resolver(void);
#endif
void zai_interceptor_activate(void) {
    zend_hash_init(&zai_hook_memory, 8, nothing, zai_hook_memory_dtor, 0);
    zend_hash_init(&zai_interceptor_implicit_generators, 8, nothing, NULL, 0);

#if PHP_VERSION_ID < 80200
    zai_interceptor_reset_resolver();
#endif
}

void zai_interceptor_deactivate(void) {
    zend_hash_destroy(&zai_hook_memory);
    zend_hash_destroy(&zai_interceptor_implicit_generators);
}
