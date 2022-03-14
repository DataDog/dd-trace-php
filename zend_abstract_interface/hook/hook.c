#include <hook/hook.h>
#include <hook/table.h>
#include <value/value.h>

// clang-format off

/* {{{ */
typedef struct {
    zend_string    *scope;
    zend_string    *function;
    zai_hook_begin begin;
    zai_hook_end   end;
    zai_hook_aux   aux;
    size_t         dynamic;
    size_t         dynamic_offset;
    bool           is_global;
    // keep track of adding / deleting so that:
    // a) deleted hooks are not called on new function invocations
    // b) freshly added hooks do not trigger an end hook
    zend_ulong     added_invocation;
    zend_ulong     deleted_invocation;
    int            invocation_refcount;
} zai_hook_t; /* }}} */

typedef struct {
    HashTable hooks;
    size_t dynamic;
    zend_function *resolved;
} zai_hooks_entry;

// clang-format on

__thread zend_ulong zai_hook_invocation = 0;

/* {{{ private tables */
// zai_hook_static is a simple array of persistently allocated zai_hook_t
// these persistently allocated zai_hook_t are always duplicated (with is_global = true) into zai_hook_request_* on request start
static HashTable zai_hook_static;

// zai_hook_request_functions is a map name -> array<zai_hook_t>
__thread HashTable zai_hook_request_functions;
// zai_hook_request_classes is a map class name -> map function name -> array<zai_hook_t>
__thread HashTable zai_hook_request_classes;

// zai_hook_resolved is a map op_array/internal_function -> array<zai_hook_t>
// if indirect, then it's pointing to some hashtable in zai_hook_request_functions/classes
__thread HashTable zai_hook_resolved; /* }}} */

#if PHP_VERSION_ID >= 80000
static void zai_hook_on_update_empty(zend_op_array *op_array, bool remove) { (void)op_array, (void)remove; }
void (*zai_hook_on_update)(zend_op_array *op_array, bool remove) = zai_hook_on_update_empty;
#endif

/* {{{ some inlines need private access */
#include <hook/memory.h> /* }}} */

static void zai_hook_data_dtor(zai_hook_t *hook) {
    if (hook->aux.dtor) {
        hook->aux.dtor(hook->aux.data);
    }

    if (hook->scope) {
        zend_string_release(hook->scope);
    }

    if (hook->function) {
        zend_string_release(hook->function);
    }
}

static void zai_hook_static_destroy(zval *zv) {
    zai_hook_t *hook = Z_PTR_P(zv);
    zai_hook_data_dtor(hook);
    pefree(hook, 1);
}

static void zai_hook_destroy(zval *zv) {
    zai_hook_t *hook = Z_PTR_P(zv);

    if (!hook->is_global) {
        zai_hook_data_dtor(hook);
    }

    efree(hook);
}

static void zai_hook_hash_destroy(zval *zv) {
    HashTable *hooks = Z_PTR_P(zv);

    zend_array_destroy(hooks);

    efree(hooks);
}

static void zai_hook_resolved_destroy(zval *zv) {
    if (Z_TYPE_P(zv) == IS_ARRAY) {
        zai_hook_hash_destroy(zv);
    }
}

/* {{{ */
static zend_function *zai_hook_resolve_function(zend_string *scope, zend_string *func) {
    zend_function *function = NULL;

    zai_string_view func_name = ZAI_STRING_FROM_ZSTR(func);
    if (scope) {
        zai_string_view class_name = ZAI_STRING_FROM_ZSTR(scope);
        zend_class_entry *ce = zai_symbol_lookup_class(ZAI_SYMBOL_SCOPE_GLOBAL, NULL,&class_name);

        if (!ce) {
            /* class not available */
            return NULL;
        }
        function = zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_CLASS, ce, &func_name);
    } else {
        function = zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &func_name);
    }
    return function;
}

static zend_long zai_hook_add_entry(zai_hooks_entry *hooks, zai_hook_t *hook) {
    zend_long index = hooks->hooks.nNextFreeElement;
    zend_hash_next_index_insert_ptr(&hooks->hooks, hook);

    hook->dynamic_offset = hooks->dynamic;
    hooks->dynamic += hook->dynamic;

    if (hooks->resolved) {
#if PHP_VERSION_ID >= 80000
        if (hooks->resolved->type == ZEND_USER_FUNCTION) {
            zai_hook_on_update(&hooks->resolved->op_array, false);
        }
#endif
    }

    return index;
}

static zend_long zai_hook_request_install(zai_hook_t *hook) {
    HashTable *funcs;
    if (hook->scope) {
        funcs = zend_hash_find_ptr(&zai_hook_request_classes, hook->scope);
        if (!funcs) {
            funcs = emalloc(sizeof(*funcs));
            zend_hash_init(funcs, 8, NULL, zai_hook_hash_destroy, 0);
            zend_hash_add_ptr(&zai_hook_request_classes, hook->scope, funcs);
        }
    } else {
        funcs = &zai_hook_request_functions;
    }

    zai_hooks_entry *hooks = zend_hash_find_ptr(funcs, hook->function);
    if (!hooks) {
        zend_function *function = zai_hook_resolve_function(hook->scope, hook->function);
        zend_ulong addr = zai_hook_install_address(function);

        zval *resolved_zv = zend_hash_index_add(&zai_hook_resolved, addr, &EG(uninitialized_zval));
        if (resolved_zv) {
            hooks = emalloc(sizeof(*hooks));
            hooks->dynamic = 0;
            hooks->resolved = NULL;
            zend_hash_init(&hooks->hooks, 8, NULL, zai_hook_destroy, 0);
        } else {
            resolved_zv = zend_hash_index_find(&zai_hook_resolved, addr);
            hooks = Z_PTR_P(resolved_zv);
        }

        ZVAL_PTR(resolved_zv, hooks);
        zend_hash_add_ptr(funcs, hook->function, hooks);
    }

    return zai_hook_add_entry(hooks, hook);
}

static zend_long zai_hook_resolved_install(zai_hook_t *hook, zend_function *resolved) {
    zend_ulong addr = zai_hook_install_address(resolved);
    zai_hooks_entry *hooks = zend_hash_index_find_ptr(&zai_hook_resolved, addr);
    if (!hooks) {
        zval zv;
        hooks = emalloc(sizeof(*hooks));
        hooks->dynamic = 0;
        hooks->resolved = resolved;

        zend_hash_init(&hooks->hooks, 8, NULL, zai_hook_destroy, 0);
        hooks->hooks.nNextFreeElement = HT_MAX_SIZE;

        ZVAL_ARR(&zv, &hooks->hooks); // IS_ARRAY for distinguishing in dtor
        zend_hash_index_add(&zai_hook_resolved, addr, &zv);
    }

    return zai_hook_add_entry(hooks, hook);
}

static inline void zai_hook_resolve(HashTable *class_ht, zend_op_array *op_array) {
    zai_hooks_entry *hooks = zend_hash_find_ptr(class_ht, op_array->function_name);
    if (!hooks) {
        return;
    }

    zend_ulong addr = zai_hook_install_address_user(op_array);
    zend_hash_index_add_ptr(&zai_hook_resolved, addr, hooks);
}

/* {{{ */
void zai_hook_resolve_user_function(zend_op_array *op_array) {
    zai_hook_resolve(&zai_hook_request_functions, op_array);
#if PHP_VERSION_ID > 80000
    // We do negative caching for run-time allocated op_arrays
    if (op_array->fn_flags & ZEND_ACC_HEAP_RT_CACHE) {
        zend_hash_index_add_ptr(&zai_hook_resolved, zai_hook_install_address_user(op_array), NULL);
    }
#endif
}
void zai_hook_resolve_class(zend_class_entry *ce) {
    HashTable *method_table = zend_hash_find_ptr(&zai_hook_request_classes, ce->name);
    if (!method_table) {
        return;
    }

    zend_op_array *op_array;
    ZEND_HASH_FOREACH_PTR(&ce->function_table, op_array) {
        zai_hook_resolve(method_table, op_array);
    } ZEND_HASH_FOREACH_END();
}

static void zai_hook_remove_from_entry(zai_hooks_entry *hooks, zend_ulong index) {
    zend_hash_index_del(&hooks->hooks, index);

    if (zend_hash_num_elements(&hooks->hooks)) {
        hooks->dynamic = 0;
        zai_hook_t *hook;
        ZEND_HASH_FOREACH_PTR(&hooks->hooks, hook) {
            hook->dynamic_offset = hooks->dynamic;
            hooks->dynamic += hook->dynamic;
        }
        ZEND_HASH_FOREACH_END();
    } else {
        zend_hash_index_del(&zai_hook_resolved, zai_hook_install_address(hooks->resolved));
        HashTable *base_ht;
        if (hooks->resolved->common.scope) {
            base_ht = zend_hash_find_ptr(&zai_hook_request_classes, hooks->resolved->common.scope->name);
        } else {
            base_ht = &zai_hook_request_functions;
        }
        if (base_ht) {
            zend_hash_del(base_ht, hooks->resolved->common.function_name);
            if (zend_hash_num_elements(base_ht) == 0 && hooks->resolved->common.scope) {
                zend_hash_del(&zai_hook_request_classes, hooks->resolved->common.scope->name);
            }
        }
    }
}

/* {{{ */
static inline zai_hooks_entry *zai_hook_find(zend_execute_data *ex) {
    zai_hooks_entry *hooks;

    if (!zai_hook_table_find(&zai_hook_resolved, zai_hook_frame_address(ex), (void**)&hooks)) {
        return NULL;
    }

    return hooks;
} /* }}} */

/* {{{ */
bool zai_hook_continue(zend_execute_data *ex, zai_hook_memory_t *memory) {
    zai_hooks_entry *hooks = zai_hook_find(ex);

    if (!hooks) {
        return true;
    }

    zai_hook_memory_allocate(memory, hooks->dynamic);

    // clang-format off
    // iterate the array in a safe way, i.e. handling possible updates at runtime
    HashPosition pos;
    zend_hash_internal_pointer_reset_ex(&hooks->hooks, &pos);
    uint32_t ht_iter = zend_hash_iterator_add(&hooks->hooks, pos);

    for (zai_hook_t *hook; (hook = zend_hash_get_current_data_ptr_ex(&hooks->hooks, &pos)); zend_hash_move_forward_ex(&hooks->hooks, &pos)) {
        ++hook->invocation_refcount;
        if (!hook->begin) {
            continue;
        }

        EG(ht_iterators)[ht_iter].pos = pos;

        if (!hook->begin(
                ex,
                hook->aux.data,
                zai_hook_memory_dynamic(memory, hook))) {
            // TODO this handling is unsafe with multiple hooks; dynamic memory will not be initialized for hook end of any skipped hooks
            goto __zai_hook_finish;
        }

        if (EG(ht_iterators)[ht_iter].ht != &hooks->hooks) {
            zai_hook_memory_free(memory);
            break;  // ht was deleted
        }
        pos = zend_hash_iterator_pos(ht_iter, &hooks->hooks);
    }

    zend_hash_iterator_del(ht_iter);
    // clang-format on

    memory->invocation = ++zai_hook_invocation;
    return true;

__zai_hook_finish:
    memory->invocation = ++zai_hook_invocation;
    zai_hook_finish(ex, NULL, memory);
    return false;
} /* }}} */

/* {{{ */
void zai_hook_finish(zend_execute_data *ex, zval *rv, zai_hook_memory_t *memory) {
    zai_hooks_entry *hooks = zai_hook_find(ex);

    if (!hooks) {
        return;
    }

    // clang-format off
    // iterate the array in a safe way, i.e. handling possible updates at runtime
    HashPosition pos;
    zend_hash_internal_pointer_reset_ex(&hooks->hooks, &pos);
    uint32_t ht_iter = zend_hash_iterator_add(&hooks->hooks, pos);

    for (zai_hook_t *hook; (hook = zend_hash_get_current_data_ptr_ex(&hooks->hooks, &pos)); zend_hash_move_forward_ex(&hooks->hooks, &pos)) {
        if (hook->added_invocation >= memory->invocation) {
            continue;
        }
        if (!hook->end || hook->deleted_invocation < memory->invocation) {
            --hook->invocation_refcount;
            continue;
        }

        EG(ht_iterators)[ht_iter].pos = pos;

        hook->end(
            ex, rv,
            hook->aux.data,
            zai_hook_memory_dynamic(memory, hook));

        if (hook->deleted_invocation < zai_hook_invocation && --hook->invocation_refcount == 0) {
            zval zv;
            zend_hash_get_current_key_zval_ex(&hooks->hooks, &zv, &pos);
            zai_hook_remove_from_entry(hooks, Z_LVAL(zv));
        }

        if (EG(ht_iterators)[ht_iter].ht != &hooks->hooks) {
            break;  // ht was deleted
        }
        pos = zend_hash_iterator_pos(ht_iter, &hooks->hooks);
    }

    zend_hash_iterator_del(ht_iter);
    // clang-format on

    zai_hook_memory_free(memory);
} /* }}} */

/* {{{ */
bool zai_hook_minit(void) {
    zend_hash_init(&zai_hook_static, 8, NULL, (dtor_func_t)zai_hook_static_destroy, 1);
    return true;
}

bool zai_hook_rinit(void) {
    zend_hash_init(&zai_hook_request_functions, 8, NULL, (dtor_func_t)zai_hook_hash_destroy, 0);
    zend_hash_init(&zai_hook_request_classes, 8, NULL, (dtor_func_t)zai_hook_hash_destroy, 0);
    zend_hash_init(&zai_hook_resolved, 8, NULL, (dtor_func_t)zai_hook_resolved_destroy, 0);

    return true;
}

void zai_hook_activate(void) {
    zai_hook_t *hook;
    ZEND_HASH_FOREACH_PTR(&zai_hook_static, hook) {
        zai_hook_t *copy = emalloc(sizeof(*copy));
        *copy = *hook;
        copy->is_global = true;
        zai_hook_request_install(copy);
    } ZEND_HASH_FOREACH_END();
}

void zai_hook_clean(void) {
    zend_hash_clean(&zai_hook_resolved);
    zend_hash_clean(&zai_hook_request_functions);
    zend_hash_clean(&zai_hook_request_classes);
}

void zai_hook_rshutdown(void) {
    zend_hash_destroy(&zai_hook_resolved);
    zend_hash_destroy(&zai_hook_request_functions);
    zend_hash_destroy(&zai_hook_request_classes);
}

void zai_hook_mshutdown(void) { zend_hash_destroy(&zai_hook_static); } /* }}} */

// clang-format off

/* {{{ */
zend_long zai_hook_install_resolved(
        zai_hook_begin begin,
        zai_hook_end end,
        zai_hook_aux aux,
        size_t dynamic,
        zend_function *function) {
    if (!PG(modules_activated)) {
        /* not allowed: can only do resolved install during request */
        return false;
    }

    zai_hook_t *hook = emalloc(sizeof(*hook));
    *hook = (zai_hook_t){
        .scope = NULL,
        .function = NULL,
        .begin = begin,
        .end = end,
        .aux = aux,
        .dynamic = dynamic,
        .dynamic_offset = 0,
        .deleted_invocation = (zend_ulong)-1,
        .invocation_refcount = 0,
    };

    return zai_hook_resolved_install(hook, function);
} /* }}} */

/* {{{ */
zend_long zai_hook_install(
        zai_string_view scope,
        zai_string_view function,
        zai_hook_begin begin,
        zai_hook_end end,
        zai_hook_aux aux,
        size_t dynamic) {

    if (!function.len) {
        /* not allowed: target must be known */
        return -1;
    }

    bool persistent = !PG(modules_activated);

    zai_hook_t *hook = pemalloc(sizeof(*hook), persistent);
    *hook = (zai_hook_t){
        .scope = scope.len ? zend_string_init(scope.ptr, scope.len, persistent) : NULL,
        .function = zend_string_init(function.ptr, function.len, persistent),
        .begin = begin,
        .end = end,
        .aux = aux,
        .dynamic = dynamic,
        .dynamic_offset = 0,
        .added_invocation = zai_hook_invocation,
        .deleted_invocation = (zend_ulong)-1,
        .invocation_refcount = 0,
    };

    if (persistent) {
        zend_hash_next_index_insert_ptr(&zai_hook_static, hook);
        return 0;
    } else {
        return zai_hook_request_install(hook);
    }
} /* }}} */

void zai_hooks_try_remove_entry(zai_hooks_entry *hooks, zend_long index) {
    zai_hook_t *hook = zend_hash_index_find_ptr(&hooks->hooks, index);
    if (!hook || hook->deleted_invocation <= zai_hook_invocation) {
        return;
    }

    if (hook->begin != NULL && hook->end != NULL && hook->invocation_refcount > 0) {
        // we have an active hook. We cannot remove it right here, but need to schedule it for deletion
        hook->deleted_invocation = zai_hook_invocation;
    } else {
        zai_hook_remove_from_entry(hooks, index);
    }
}

void zai_hook_remove_resolved(zend_function *function, zend_long index) {
    if (function->common.function_name) {
        zai_hook_remove(function->common.scope ? ZAI_STRING_FROM_ZSTR(function->common.scope->name) : ZAI_STRING_EMPTY,
                        ZAI_STRING_FROM_ZSTR(function->common.function_name), index);
    }

    zai_hooks_entry *hooks = zend_hash_index_find_ptr(&zai_hook_resolved, zai_hook_install_address(function));
    if (hooks) {
        zai_hooks_try_remove_entry(hooks, index);
    }
}

void zai_hook_remove(zai_string_view scope, zai_string_view function, zend_long index) {
    HashTable *base_ht;
    if (scope.len) {
        base_ht = zend_hash_str_find_ptr(&zai_hook_request_classes, scope.ptr, scope.len);
        if (!base_ht) {
            return;
        }
    } else {
        base_ht = &zai_hook_request_functions;
    }
    zai_hooks_entry *hooks = zend_hash_str_find_ptr(base_ht, function.ptr, function.len);
    if (hooks) {
        zai_hooks_try_remove_entry(hooks, index);
    }
}

// clang-format on

static void zai_hook_iterator_set_current(zai_hook_iterator *it) {
    zai_hook_t *hook = zend_hash_get_current_data_ptr_ex(it->iterator.ht, &it->iterator.pos);
    if (hook) {
        zend_hash_get_current_key_ex(it->iterator.ht, NULL, &it->index, &it->iterator.pos);
        it->begin = &hook->begin;
        it->end = &hook->end;
        it->aux = &hook->aux;
    } else {
        it->active = false;
    }
}

zai_hook_iterator zai_hook_iterate_installed(zai_string_view scope, zai_string_view function) {
    HashTable *base_ht;
    if (scope.len) {
        base_ht = zend_hash_str_find_ptr(&zai_hook_request_classes, scope.ptr, scope.len);
        if (!base_ht) {
            return (zai_hook_iterator){0};
        }
    } else {
        base_ht = &zai_hook_request_functions;
    }
    HashTable *hooks = zend_hash_str_find_ptr(base_ht, function.ptr, function.len);
    if (hooks) {
        zai_hook_iterator it;
        it.active = true;
        it.iterator = (HashTableIterator){ .ht = hooks, .pos = 0 };
        zai_hook_iterator_set_current(&it);
        return it;
    } else {
        return (zai_hook_iterator){0};
    }
}

zai_hook_iterator zai_hook_iterate_resolved(zend_function *function) {
    HashTable *hooks = zend_hash_index_find_ptr(&zai_hook_resolved, zai_hook_install_address(function));
    if (hooks) {
        zai_hook_iterator it;
        it.active = true;
        it.iterator = (HashTableIterator){ .ht = hooks, .pos = 0 };
        zai_hook_iterator_set_current(&it);
        return it;
    } else {
        return (zai_hook_iterator){0};
    }
}

void zai_hook_iterator_advance(zai_hook_iterator *iterator) {
    zend_hash_move_forward_ex(iterator->iterator.ht, &iterator->iterator.pos);
    zai_hook_iterator_set_current(iterator);
}
