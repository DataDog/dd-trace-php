#include <Zend/zend_observer.h>
#include <hook/hook.h>
#include <hook/table.h>
#include <Zend/zend_generators.h>

__thread HashTable zai_hook_memory;
// execute_data is 16 byte aligned (except when it isn't, but it doesn't matter as zend_execute_data is big enough
// our goal is to reduce conflicts
static inline bool zai_hook_memory_table_insert(zend_execute_data *index, zai_hook_memory_t *inserting) {
    void *inserted;
    return zai_hook_table_insert_at(&zai_hook_memory, ((zend_ulong)index) >> 4, inserting, sizeof(*inserting), &inserted);
}

static inline bool zai_hook_memory_table_find(zend_execute_data *index, zai_hook_memory_t **found) {
    return zai_hook_table_find(&zai_hook_memory, ((zend_ulong)index) >> 4, (void **)found);
}

static inline bool zai_hook_memory_table_del(zend_execute_data *index) {
    return zend_hash_index_del(&zai_hook_memory, ((zend_ulong)index) >> 4);
}

static void zai_hook_safe_finish(register zend_execute_data *execute_data, register zval *retval, register zai_hook_memory_t *frame_memory) {
    if (!CG(unclean_shutdown)) {
        zai_hook_finish(execute_data, retval, frame_memory);
        return;
    }

    // executing code may write to the stack as normal part of its execution. Jump onto a temporary stack here... to avoid messing with stack allocated data
    JMP_BUF target;
    const size_t stack_size = 1 << 17;
    void *volatile stack = malloc(stack_size);
    if (SETJMP(target) == 0) {
        void *stacktop = stack + stack_size;
        __asm__ volatile("mov %0, %%rsp" : : "r"(stacktop));
        zai_hook_finish(execute_data, retval, frame_memory);
        LONGJMP(target, 1);
    }
    free(stack);
}

static void zai_interceptor_observer_begin_handler(zend_execute_data *execute_data) {
    zai_hook_memory_t frame_memory;
    if (zai_hook_continue(execute_data, &frame_memory)) {
        zai_hook_memory_table_insert(execute_data, &frame_memory);
    }
}

static void zai_interceptor_observer_end_handler(zend_execute_data *execute_data, zval *retval) {
    zai_hook_memory_t *frame_memory;
    if (zai_hook_memory_table_find(execute_data, &frame_memory)) {
        zval rv;
        if (!retval) {
            ZVAL_NULL(&rv);
            retval = &rv;
        }
        zai_hook_safe_finish(execute_data, retval, frame_memory);
        zai_hook_memory_table_del(execute_data);
    }
}

static void zai_interceptor_observer_generator_end_handler(zend_execute_data *execute_data, zval *retval) {
    zend_generator *generator = (zend_generator *)execute_data->return_value;
    if (!EG(exception) && Z_ISUNDEF(generator->retval)) {
        return;
    }

    zai_hook_memory_t *frame_memory;
    if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory)) {
        zval rv;
        if (!retval) {
            ZVAL_NULL(&rv);
            retval = &rv;
        }
        zai_hook_safe_finish(execute_data, retval, frame_memory);
        zai_hook_memory_table_del((zend_execute_data *)generator);
    }
}

// TODO ... listen on hooks for change on installed hooks?
#define ZEND_OBSERVER_DATA(op_array) \
	ZEND_OP_ARRAY_EXTENSION(op_array, zend_observer_fcall_op_array_extension)
#define ZEND_OBSERVER_NOT_OBSERVED ((void *) 2)

typedef struct {
    // points after the last handler
    zend_observer_fcall_handlers *end;
    // a variadic array using "struct hack"
    zend_observer_fcall_handlers handlers[1];
} zend_observer_fcall_data;

void zai_interceptor_replace_observer(zend_op_array *op_array, bool remove) {
    zend_observer_fcall_data *data = ZEND_OBSERVER_DATA(op_array);
    if (remove) {
        for (zend_observer_fcall_handlers *handlers = data->handlers, *end = data->end; handlers != end; ++handlers) {
            if (handlers->end == zai_interceptor_observer_end_handler || handlers->end == zai_interceptor_observer_generator_end_handler) {
                if (data->handlers == end + 1) {
                    data->end = data->handlers;
                } else {
                    *handlers = *(end - 1);
                    data->end = end - 1;
                }
                break;
            }
        }
    } else {
        // TODO subtly leaks memory, given that we discard the old arena allocated handler list
        // Currently by design as Closure rebinding anyway leaks memory, but it's very ugly
        ZEND_OBSERVER_DATA(op_array) = NULL;
    }
}


static zend_observer_fcall_handlers zai_interceptor_observer_fcall_init(zend_execute_data *execute_data) {
    // TODO: resolve only hooks for current function to avoid lookup overhead of yet unresolved hooks?
    // Not sure whether the current zai hooks design makes sense here... - observer design forces us to do decisions at first-call time
    // I guess doing a class -> name & simple name lookup would be better here
    zai_hook_resolve();
    zend_op_array *op_array = &execute_data->func->op_array;
    if (UNEXPECTED(zai_hook_installed_user(op_array))) {
        if (op_array->fn_flags & ZEND_ACC_GENERATOR) {
            return (zend_observer_fcall_handlers){NULL, zai_interceptor_observer_generator_end_handler};
        }
        return (zend_observer_fcall_handlers){zai_interceptor_observer_begin_handler, zai_interceptor_observer_end_handler};
    }

    return (zend_observer_fcall_handlers){NULL, NULL};
}

static zend_object *(*generator_create_prev)(zend_class_entry *class_type);
static zend_object *zai_interceptor_generator_create(zend_class_entry *class_type) {
    zend_generator *generator = (zend_generator *)generator_create_prev(class_type);

    zai_hook_memory_t frame_memory;
    if (zai_hook_continue(EG(current_execute_data), &frame_memory)) {
        zai_hook_memory_table_insert((zend_execute_data *)generator, &frame_memory);
    }

    return &generator->std;
}

static zend_object_dtor_obj_t zai_interceptor_generator_dtor_obj;
static void zai_interceptor_generator_dtor_wrapper(zend_object *object) {
    zend_generator *generator = (zend_generator *)object;

    zai_hook_memory_t *frame_memory;
    if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory)) {
        // generator dtor frees it
        zend_execute_data ex = *generator->execute_data;

        zai_interceptor_generator_dtor_obj(object);

        // may have returned in the dtor, don't execute twice
        if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory)) {
            // aborted generator
            zval retval;
            ZVAL_NULL(&retval);
            zai_hook_finish(&ex, &retval, frame_memory);
            zai_hook_memory_table_del((zend_execute_data *)generator);
        }
    }
}

static void (*prev_execute_internal)(zend_execute_data *execute_data, zval *return_value);
static inline void zai_interceptor_execute_internal_impl(zend_execute_data *execute_data, zval *return_value, bool prev) {
    zend_function *func = execute_data->func;
    if (UNEXPECTED(zai_hook_installed_internal(&func->internal_function))) {
        zai_hook_memory_t frame_memory;
        if (zai_hook_continue(execute_data, &frame_memory)) {
            zai_hook_memory_table_insert(execute_data, &frame_memory);
        }

        // we do not use try / catch here as to preserve order of hooks, LIFO style, in bailout handler
        if (prev) {
            prev_execute_internal(execute_data, return_value);
        } else {
            func->internal_function.handler(execute_data, return_value);
        }

        zai_hook_finish(execute_data, return_value, &frame_memory);
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

void zai_interceptor_minit() {
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
}

static void zai_hook_memory_dtor(zval *zv) {
    efree(Z_PTR_P(zv));
}

void zai_interceptor_rinit() {
    zend_hash_init(&zai_hook_memory, 8, nothing, zai_hook_memory_dtor, 0);
}

void zai_interceptor_rshutdown() {
    zend_hash_destroy(&zai_hook_memory);
}
