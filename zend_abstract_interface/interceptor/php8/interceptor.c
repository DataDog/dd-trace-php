#include <Zend/zend_observer.h>
#include <hook/hook.h>
#include <hook/table.h>
#include <Zend/zend_extensions.h>
#include <Zend/zend_generators.h>
#include "interceptor.h"

#ifdef __SANITIZE_ADDRESS__
# include <sanitizer/common_interface_defs.h>
#endif

static int registered_observers = 0;

typedef struct {
    zai_hook_memory_t hook_data;
    zend_execute_data *ex;
    bool resumed;
    bool implicit;
} zai_interceptor_frame_memory;

__thread HashTable zai_interceptor_implicit_generators;
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

static void zai_hook_safe_finish(register zend_execute_data *execute_data, register zval *retval, register zai_interceptor_frame_memory *frame_memory) {
    if (!CG(unclean_shutdown)) {
        zai_hook_finish(execute_data, retval, &frame_memory->hook_data);
        return;
    }

#ifdef __SANITIZE_ADDRESS__
    const void *bottom;
    size_t capacity;
#endif

    // executing code may write to the stack as normal part of its execution. Jump onto a temporary stack here... to avoid messing with stack allocated data
    JMP_BUF target;
    const size_t stack_size = 1 << 17;
    void *volatile stack = malloc(stack_size);
    if (SETJMP(target) == 0) {
        void *stacktop = stack + stack_size, *stacktarget = stacktop - 0x400;

#ifdef __SANITIZE_ADDRESS__
        void *volatile fake_stack;
    	__sanitizer_start_switch_fiber((void**) &fake_stack, stacktop, stack_size);
#endif

#if defined(__x86_64__)
        __asm__ volatile("mov %0, %%rsp" : : "r"(stacktarget));
#elif defined(__aarch64__)
        __asm__ volatile("mov sp, %0" : : "r"(stacktarget));
#endif

#ifdef __SANITIZE_ADDRESS__
        __sanitizer_finish_switch_fiber(fake_stack, &bottom, &capacity);
#endif

        zai_hook_finish(execute_data, retval, &frame_memory->hook_data);

#ifdef __SANITIZE_ADDRESS__
    	__sanitizer_start_switch_fiber(NULL, bottom, capacity);
#endif

        LONGJMP(target, 1);
    }

#ifdef __SANITIZE_ADDRESS__
    __sanitizer_finish_switch_fiber(NULL, &bottom, &capacity);
#endif

    free(stack);
}

static void zai_interceptor_observer_begin_handler(zend_execute_data *execute_data) {
    zai_interceptor_frame_memory frame_memory;
    if (zai_hook_continue(execute_data, &frame_memory.hook_data) == ZAI_HOOK_CONTINUED) {
        frame_memory.ex = execute_data;
        zai_hook_memory_table_insert(execute_data, &frame_memory);
    }
}

static void zai_interceptor_observer_end_handler(zend_execute_data *execute_data, zval *retval) {
    zai_interceptor_frame_memory *frame_memory;
    if (zai_hook_memory_table_find(execute_data, &frame_memory)) {
        if (!retval) {
            retval = &EG(uninitialized_zval);
        }
        zai_hook_safe_finish(execute_data, retval, frame_memory);
        zai_hook_memory_table_del(execute_data);
    }
}

// Note: This is not optimized. I.e. a scenario where an observed generator yields from other generators which do a recursive yield from, every single yield in that generator will have an O(nested generators) performance.
// In real world I've yet to see excessive recursion of generators, but here is room for potential future optimizations
static void zai_interceptor_generator_yielded(zend_execute_data *ex, zval *key, zval *yielded, zai_interceptor_frame_memory *frame_memory) {
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

static void zai_interceptor_generator_resumption(zend_execute_data *ex, zval *sent, zai_interceptor_frame_memory *frame_memory) {
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

    zai_interceptor_frame_memory *frame_memory;
    if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory)) {
        zai_interceptor_generator_resumption(execute_data, !EG(exception) && generator->send_target ? generator->send_target : &EG(uninitialized_zval), frame_memory);
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
    zai_interceptor_frame_memory *frame_memory;
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
    zai_interceptor_generator_yielded(it->generator->execute_data, key, zend_hash_get_current_data_ex(it->array, &Z_FE_POS(it->zv)), it->frame_memory);
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

#if PHP_VERSION_ID < 80200
void (*zai_interceptor_replace_observer)(zend_op_array *op_array, bool remove);
#else
void zai_interceptor_replace_observer(zend_op_array *op_array, bool remove);
#endif

static void zai_interceptor_observer_generator_yield(zend_execute_data *execute_data, zval *retval, zend_generator *generator, zai_interceptor_frame_memory *frame_memory) {
    if (generator->execute_data && (generator->execute_data->opline - 1)->opcode == ZEND_YIELD_FROM) {
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
                zai_interceptor_generator_yielded(execute_data, &root->key, &root->value, frame_memory);
            }

            generator = generator->node.parent;
            zai_install_address genaddr = zai_hook_install_address_user(&generator->execute_data->func->op_array);
            while (!zend_hash_index_exists(&zai_hook_resolved, genaddr)) {
                zval *count = zend_hash_index_find(&zai_interceptor_implicit_generators, genaddr);
                if (!count) {
                    zai_interceptor_replace_observer(&generator->execute_data->func->op_array, false);

                    zval one;
                    ZVAL_LONG(&one, 1);
                    zend_hash_index_add(&zai_interceptor_implicit_generators, genaddr, &one);
                } else if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory)) {
                    break;
                } else {
                    ++Z_LVAL_P(count);
                }
                zai_interceptor_frame_memory generator_memory;
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
            it->it.funcs = Z_TYPE(generator->values) == IS_ARRAY ? &zai_interceptor_iterator_wrapper_array_funcs : &zai_interceptor_iterator_wrapper_iterator_funcs;

            zend_iterator_init(&it->it);
            ZVAL_OBJ(&generator->values, &it->it.std);
        }
    } else {
        zai_interceptor_generator_yielded(execute_data, &generator->key, retval, frame_memory);
    }
}

static void zai_interceptor_handle_ended_generator(zend_generator *generator, zend_execute_data *execute_data, zval *retval, zai_interceptor_frame_memory *frame_memory) {
    if (frame_memory->implicit) {
        zai_install_address genaddr = zai_hook_install_address_user(&generator->execute_data->func->op_array);
        zval *count = zend_hash_index_find(&zai_interceptor_implicit_generators, genaddr);
        if (count && !--Z_LVAL_P(count)) {
            zend_hash_index_del(&zai_interceptor_implicit_generators, genaddr);
            if (!zend_hash_index_exists(&zai_hook_resolved, genaddr)) {
                zai_interceptor_replace_observer(&generator->execute_data->func->op_array, true);
            }
        }
    } else {
        zai_hook_safe_finish(execute_data, retval, frame_memory);
    }
    zai_hook_memory_table_del((zend_execute_data *)generator);
}

static void zai_interceptor_observer_generator_end_handler(zend_execute_data *execute_data, zval *retval) {
    zend_generator *generator = (zend_generator *)execute_data->return_value;

    zai_interceptor_frame_memory *frame_memory;
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

static inline zend_observer_fcall_handlers zai_interceptor_determine_handlers(zend_op_array *op_array) {
    if (op_array->fn_flags & ZEND_ACC_GENERATOR) {
        return (zend_observer_fcall_handlers){zai_interceptor_observer_generator_resumption_handler, zai_interceptor_observer_generator_end_handler};
    }
    return (zend_observer_fcall_handlers){zai_interceptor_observer_begin_handler, zai_interceptor_observer_end_handler};
}

#define ZEND_OBSERVER_DATA(op_array) \
	ZEND_OP_ARRAY_EXTENSION(op_array, zend_observer_fcall_op_array_extension)

#define ZEND_OBSERVER_NOT_OBSERVED ((void *) 2)

#if PHP_VERSION_ID < 80200
typedef struct {
    // points after the last handler
    zend_observer_fcall_handlers *end;
    // a variadic array using "struct hack"
    zend_observer_fcall_handlers handlers[1];
} zend_observer_fcall_data;

void zai_interceptor_replace_observer_legacy(zend_op_array *op_array, bool remove) {
    if (!RUN_TIME_CACHE(op_array)) {
        return;
    }

    if ((op_array->fn_flags & ZEND_ACC_GENERATOR) && zend_hash_index_find(&zai_interceptor_implicit_generators, zai_hook_install_address_user(op_array))) {
        return;
    }

    zend_observer_fcall_data *data = ZEND_OBSERVER_DATA(op_array);
    if (!data) {
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
        *(data->end++) = zai_interceptor_determine_handlers(op_array);
    }
}

#define zai_interceptor_replace_observer zai_interceptor_replace_observer_current

// Allocate some space. This space can be used to install observers afterwards
// ... I would love to make use of ZEND_OBSERVER_NOT_OBSERVED optimization, but this does not seem possible :-(
static void zai_interceptor_observer_placeholder_handler(zend_execute_data *execute_data) {
    zend_observer_fcall_data *data = ZEND_OBSERVER_DATA(&execute_data->func->op_array);
    for (zend_observer_fcall_handlers *handlers = data->handlers, *end = data->end; handlers != end; ++handlers) {
        if (handlers->begin == zai_interceptor_observer_placeholder_handler) {
            if (handlers == end - 1) {
                handlers->begin = NULL;
            } else {
                *handlers = *(end - 1);
                handlers->begin(execute_data);
            }
            data->end = end - 1;
            break;
        }
    }
}
#endif

// This function MUST NOT be called with remove = false if there is already an observer installed and it also MUST NOT be called with remove = true if there is no observer installed yet
void zai_interceptor_replace_observer(zend_op_array *op_array, bool remove) {
    if (!RUN_TIME_CACHE(op_array)) {
        return;
    }

    if ((op_array->fn_flags & ZEND_ACC_GENERATOR) && zend_hash_index_find(&zai_interceptor_implicit_generators, zai_hook_install_address_user(op_array))) {
        return;
    }

    zend_observer_fcall_begin_handler *beginHandler = (zend_observer_fcall_begin_handler *)&ZEND_OBSERVER_DATA(op_array), *beginEnd = beginHandler + registered_observers - 1;
    zend_observer_fcall_end_handler *endHandler = (zend_observer_fcall_end_handler *)beginEnd + 1, *endEnd = endHandler + registered_observers - 1;

    if (remove) {
        for (zend_observer_fcall_begin_handler *curHandler = beginHandler; curHandler <= beginEnd; ++curHandler) {
            if (*curHandler == zai_interceptor_observer_begin_handler || *curHandler == zai_interceptor_observer_generator_resumption_handler) {
                if (registered_observers == 1 || (curHandler == beginHandler && curHandler[1] == NULL)) {
                    *curHandler = ZEND_OBSERVER_NOT_OBSERVED;
                } else {
                    if (curHandler != beginEnd) {
                        memmove(curHandler, curHandler + 1, sizeof(curHandler) * (beginEnd - curHandler));
                    } else {
                        *beginEnd = NULL;
                    }
                }
                break;
            }
        }

        for (zend_observer_fcall_end_handler *curHandler = endHandler; curHandler <= endEnd; ++curHandler) {
            if (*curHandler == zai_interceptor_observer_end_handler || *curHandler == zai_interceptor_observer_generator_end_handler) {
                if (registered_observers == 1 || (curHandler == endHandler && *(curHandler + 1) == NULL)) {
                    *curHandler = ZEND_OBSERVER_NOT_OBSERVED;
                } else {
                    if (curHandler != endEnd) {
                        memmove(curHandler, curHandler + 1, sizeof(curHandler) * (endEnd - curHandler));
                    } else {
                        *endEnd = NULL;
                    }
                }
                break;
            }
        }
    } else {
        // preserve the invariant that end handlers are in reverse order of begin handlers
        zend_observer_fcall_handlers handlers = zai_interceptor_determine_handlers(op_array);
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
            memmove(endHandler + 1, endHandler, endEnd - endHandler);
        }
        *endHandler = handlers.end;
    }
}

static zend_always_inline bool zai_interceptor_shall_install_handlers(zend_op_array *op_array) {
    // We opt to always install observers for runtime op_arrays with dynamic runtime cache, as we cannot find them reliably and inexpensively at runtime (e.g. dynamic closures) when observers change
    if (UNEXPECTED((op_array->fn_flags & ZEND_ACC_HEAP_RT_CACHE) != 0)) {
        // Note that only run-time constructs like Closures and top-level code (which we ignore) are HEAP_RT_CACHE. Given that closures are always hooked at runtime only, there's no need for runtime resolving
        return true;
    } else {
        if (zai_hook_installed_user(op_array) ||
            ((op_array->fn_flags & ZEND_ACC_GENERATOR) && zend_hash_index_exists(&zai_interceptor_implicit_generators, zai_hook_install_address_user(op_array)))) {
            return true;
        }
    }
    return false;
}

static zend_observer_fcall_handlers zai_interceptor_observer_fcall_init(zend_execute_data *execute_data) {
    zend_op_array *op_array = &execute_data->func->op_array;
    if (UNEXPECTED(zai_interceptor_shall_install_handlers(op_array))) {
        return zai_interceptor_determine_handlers(op_array);
    }

#if PHP_VERSION_ID < 80200
#undef zai_interceptor_replace_observer
    // Use one-time begin handler which will remove itself
    return (zend_observer_fcall_handlers){zai_interceptor_replace_observer == zai_interceptor_replace_observer_current ? NULL : zai_interceptor_observer_placeholder_handler, NULL};
#else
    return (zend_observer_fcall_handlers){NULL, NULL};
#endif
}

static zend_object *(*generator_create_prev)(zend_class_entry *class_type);
static zend_object *zai_interceptor_generator_create(zend_class_entry *class_type) {
    zend_generator *generator = (zend_generator *)generator_create_prev(class_type);

    zai_interceptor_frame_memory frame_memory;
    zend_execute_data *execute_data = EG(current_execute_data);
    if (zai_hook_continue(execute_data, &frame_memory.hook_data) == ZAI_HOOK_CONTINUED) {
        frame_memory.resumed = false;
        frame_memory.implicit = false;
        frame_memory.ex = execute_data;
        zai_hook_memory_table_insert((zend_execute_data *)generator, &frame_memory);
    }

    return &generator->std;
}

static zend_object_dtor_obj_t zai_interceptor_generator_dtor_obj;
static void zai_interceptor_generator_dtor_wrapper(zend_object *object) {
    zend_generator *generator = (zend_generator *)object;

    zai_interceptor_frame_memory *frame_memory;
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

static void (*prev_execute_internal)(zend_execute_data *execute_data, zval *return_value);
static inline void zai_interceptor_execute_internal_impl(zend_execute_data *execute_data, zval *return_value, bool prev) {
    zend_function *func = execute_data->func;
    if (UNEXPECTED(zai_hook_installed_internal(&func->internal_function))) {
        zai_interceptor_frame_memory frame_memory;
        if (zai_hook_continue(execute_data, &frame_memory.hook_data) != ZAI_HOOK_CONTINUED) {
            goto skip;
        }
        frame_memory.ex = execute_data;
        zai_hook_memory_table_insert(execute_data, &frame_memory);

        zend_try {
            if (prev) {
                prev_execute_internal(execute_data, return_value);
            } else {
                func->internal_function.handler(execute_data, return_value);
            }
        } zend_catch {
            zend_execute_data *active_execute_data = EG(current_execute_data);

            // We need to ensure order of hooks being preserved
            zai_interceptor_frame_memory *frame;
            ZEND_HASH_REVERSE_FOREACH_PTR(&zai_hook_memory, frame) {
                // TODO: fibers. We probably need a hashtable _per fiber_?
                zend_execute_data *frame_ex = frame->ex;
                if (!(frame_ex->func->common.fn_flags & ZEND_ACC_GENERATOR)) {
                    // generators are freed separately, upon their normal destruction
                    EG(current_execute_data) = execute_data; // otherwise we're confusing the observers, with prev_execute_data getting set to current_execute_data which is NULL in zai symbol calls.
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

// extension handles are supposed to be frozen at post_startup time and observer extension handle allocation
// incidentally is right before the defacto freeze via zend_finalize_system_id
static zend_result (*prev_post_startup)(void);
zend_result zai_interceptor_post_startup(void) {
    registered_observers = (zend_op_array_extension_handles - zend_observer_fcall_op_array_extension) / 2;
    return prev_post_startup ? prev_post_startup() : SUCCESS;
}

void zai_interceptor_setup_resolving_startup(void);

void zai_interceptor_startup() {
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
#endif

    prev_execute_internal = zend_execute_internal;
    zend_execute_internal = prev_execute_internal ? zai_interceptor_execute_internal : zai_interceptor_execute_internal_no_prev;

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

    prev_post_startup = zend_post_startup_cb;
    zend_post_startup_cb = zai_interceptor_post_startup;

    zai_hook_on_update = zai_interceptor_replace_observer;

    zai_interceptor_setup_resolving_startup();
}

static void zai_hook_memory_dtor(zval *zv) {
    efree(Z_PTR_P(zv));
}

void zai_interceptor_rinit() {
    zend_hash_init(&zai_hook_memory, 8, nothing, zai_hook_memory_dtor, 0);
    zend_hash_init(&zai_interceptor_implicit_generators, 8, nothing, NULL, 0);
}

void zai_interceptor_rshutdown() {
    zend_hash_destroy(&zai_hook_memory);
    zend_hash_destroy(&zai_interceptor_implicit_generators);
}
