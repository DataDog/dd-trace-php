#include <Zend/zend_observer.h>
#include <hook/hook.h>
#include <hook/table.h>
#include <Zend/zend_extensions.h>
#include <Zend/zend_generators.h>

static int registered_observers = 0;

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
#if defined(__x86_64__)
        __asm__ volatile("mov %0, %%rsp" : : "r"(stacktop));
#elif defined(__aarch64__)
        __asm__ volatile("mov sp, %0" : : "r"(stacktop));
#endif
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

static void zai_interceptor_observer_generator_resumption_handler(zend_execute_data *execute_data) {
    zend_generator *generator = (zend_generator *)execute_data->return_value;

    zai_hook_memory_t *frame_memory;
    if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory)) {
        zai_hook_generator_resumption(execute_data, !EG(exception) && generator->send_target ? generator->send_target : &EG(uninitialized_zval), frame_memory);
    }
}

static void zai_interceptor_observer_generator_end_handler(zend_execute_data *execute_data, zval *retval) {
    zend_generator *generator = (zend_generator *)execute_data->return_value;

    zai_hook_memory_t *frame_memory;
    if (zai_hook_memory_table_find((zend_execute_data *)generator, &frame_memory)) {
        if (!EG(exception) && Z_ISUNDEF(generator->retval)) {
            zai_hook_generator_yielded(execute_data, &generator->key, retval, frame_memory);
        } else {
            zval rv;
            if (!retval) {
                ZVAL_NULL(&rv);
                retval = &rv;
            }
            zai_hook_safe_finish(execute_data, retval, frame_memory);
            zai_hook_memory_table_del((zend_execute_data *)generator);
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

    zend_observer_fcall_data *data = ZEND_OBSERVER_DATA(op_array);
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

void (*zai_interceptor_replace_observer)(zend_op_array *op_array, bool remove);
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

void zai_interceptor_replace_observer(zend_op_array *op_array, bool remove) {
    if (!RUN_TIME_CACHE(op_array)) {
        return;
    }

    zend_observer_fcall_begin_handler *beginHandler = ZEND_OBSERVER_DATA(op_array), *beginEnd = beginHandler + registered_observers - 1;
    zend_observer_fcall_end_handler *endHandler = (void *)beginEnd + 1, *endEnd = endHandler + registered_observers - 1;

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


static zend_observer_fcall_handlers zai_interceptor_observer_fcall_init(zend_execute_data *execute_data) {
    zend_op_array *op_array = &execute_data->func->op_array;
    // We opt to always install observers for runtime op_arrays with dynamic runtime cache, as we cannot find them reliably and inexpensively at runtime (e.g. dynamic closures) when observers change
    if (UNEXPECTED((op_array->fn_flags & ZEND_ACC_HEAP_RT_CACHE) != 0) || UNEXPECTED(zai_hook_installed_user(op_array))) {
        return zai_interceptor_determine_handlers(op_array);
    }
    // Use one-time begin handler which will remove itself
#if PHP_VERSION_ID < 80200
#undef zai_interceptor_replace_observer
    return (zend_observer_fcall_handlers){zai_interceptor_replace_observer == zai_interceptor_replace_observer_current ? NULL : zai_interceptor_observer_placeholder_handler, NULL};
#else
    return (zend_observer_fcall_handlers){NULL, NULL};
#endif
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
        if (!zai_hook_continue(execute_data, &frame_memory)) {
            goto skip;
        }
        zai_hook_memory_table_insert(execute_data, &frame_memory);

        // we do not use try / catch here as to preserve order of hooks, LIFO style, in bailout handler
        if (prev) {
            prev_execute_internal(execute_data, return_value);
        } else {
            func->internal_function.handler(execute_data, return_value);
        }

        zai_hook_finish(execute_data, return_value, &frame_memory);
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
static zend_result (*prev_post_startup)();
zend_result zai_interceptor_post_startup() {
    registered_observers = zend_op_array_extension_handles - zend_observer_fcall_op_array_extension;
    return prev_post_startup ? prev_post_startup() : SUCCESS;
}

void zai_interceptor_minit() {
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
