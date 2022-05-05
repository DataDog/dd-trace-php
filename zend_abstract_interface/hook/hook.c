#include <hook/hook.h>
#include <hook/table.h>
#include <value/value.h>

// clang-format off

/* {{{ */
typedef struct {
    zend_string    *scope;
    zend_string    *function;
    zai_hook_begin begin;
    zai_hook_generator_resume generator_resume;
    zai_hook_generator_yield generator_yield;
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
__thread zend_ulong zai_hook_id;

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

// Manually polyfill the poisoning here to avoid https://github.com/php/php-src/issues/8438
static void _zend_hash_iterators_remove(HashTable *ht)
{
    HashTableIterator *iter = EG(ht_iterators);
    HashTableIterator *end  = iter + EG(ht_iterators_used);

    while (iter != end) {
        if (iter->ht == ht) {
            iter->ht = (void *)-1;
        }
        iter++;
    }
}

static void zend_hash_iterators_remove(HashTable *ht) {
    if (ht->u.v.nIteratorsCount) {
        _zend_hash_iterators_remove(ht);
        ht->u.v.nIteratorsCount = 0;
    }
}


static void zai_hook_hash_destroy(zval *zv) {
    HashTable *hooks = Z_PTR_P(zv);

    zend_hash_iterators_remove(hooks);
    zend_hash_destroy(hooks);

    efree(hooks);
}

/* {{{ */
static zend_function *zai_hook_lookup_function(zai_string_view scope, zai_string_view func) {
    zend_function *function = NULL;

    if (scope.len) {
        zend_class_entry *ce = zai_symbol_lookup_class(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &scope);

        if (!ce) {
            /* class not available */
            return NULL;
        }
        function = zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_CLASS, ce, &func);
    } else {
        function = zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &func);
    }
    return function;
}

static zend_long zai_hook_add_entry(zai_hooks_entry *hooks, zai_hook_t *hook) {
    zend_long index = hooks->hooks.nNextFreeElement;
    zend_hash_index_add_ptr(&hooks->hooks, zai_hook_id++, hook);

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

static zend_long zai_hook_resolved_install(zai_hook_t *hook, zend_function *resolved) {
    zend_ulong addr = zai_hook_install_address(resolved);
    zai_hooks_entry *hooks = zend_hash_index_find_ptr(&zai_hook_resolved, addr);
    if (!hooks) {
        hooks = emalloc(sizeof(*hooks));
        hooks->dynamic = 0;
        hooks->resolved = resolved;
        zend_hash_init(&hooks->hooks, 8, NULL, zai_hook_destroy, 0);

        zend_hash_index_add_ptr(&zai_hook_resolved, addr, hooks);
    }

    return zai_hook_add_entry(hooks, hook);
}

static zend_long zai_hook_request_install(zai_hook_t *hook) {
    zend_function *function = zai_hook_lookup_function(
        hook->scope ? ZAI_STRING_FROM_ZSTR(hook->scope) : ZAI_STRING_EMPTY, ZAI_STRING_FROM_ZSTR(hook->function));
    if (function) {
        return zai_hook_resolved_install(hook, function);
    }
    
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
        hooks = emalloc(sizeof(*hooks));
        hooks->dynamic = 0;
        hooks->resolved = NULL;
        zend_hash_init(&hooks->hooks, 8, NULL, zai_hook_destroy, 0);

        zend_hash_add_ptr(funcs, hook->function, hooks);
    }

    return zai_hook_add_entry(hooks, hook);
}

static inline void zai_hook_resolve(HashTable *base_ht, zend_function *function, zend_string *lcname) {
    zai_hooks_entry *hooks = zend_hash_find_ptr(base_ht, lcname);
    if (!hooks) {
        return;
    }

    zend_ulong addr = zai_hook_install_address(function);
    if (!zend_hash_index_add_ptr(&zai_hook_resolved, addr, hooks)) {
        // it's already there (e.g. thanks to aliases, traits, ...), merge it
        zai_hooks_entry *existingHooks = zend_hash_index_find_ptr(&zai_hook_resolved, addr);
        zval *hook_zv;
        zend_ulong index;
        ZEND_HASH_FOREACH_NUM_KEY_VAL(&hooks->hooks, index, hook_zv) {
            zai_hook_t *hook = Z_PTR_P(hook_zv);
            hook->dynamic_offset = existingHooks->dynamic;
            existingHooks->dynamic += hook->dynamic;
            zend_hash_index_update(&existingHooks->hooks, index, hook_zv);
        } ZEND_HASH_FOREACH_END();

        // we remove the whole zai_hooks_entry, excluding the individual zai_hook_t which we moved
        hooks->hooks.pDestructor = NULL;
        zend_hash_del(base_ht, lcname);
    } else {
        // we remove the function entry in the base table, but preserve the zai_hooks_entry
        base_ht->pDestructor = NULL;
        zend_hash_del(base_ht, lcname);
        base_ht->pDestructor = zai_hook_hash_destroy;
    }
}

/* {{{ */
void zai_hook_resolve_function(zend_function *function, zend_string *lcname) {
    zai_hook_resolve(&zai_hook_request_functions, function, lcname);
#if PHP_VERSION_ID > 80000
    // We do negative caching for run-time allocated op_arrays
    if (function->common.fn_flags & ZEND_ACC_HEAP_RT_CACHE) {
        zend_hash_index_add_ptr(&zai_hook_resolved, zai_hook_install_address(function), NULL);
    }
#endif
}
void zai_hook_resolve_class(zend_class_entry *ce, zend_string *lcname) {
    HashTable *method_table = zend_hash_find_ptr(&zai_hook_request_classes, lcname);
    if (!method_table) {
        return;
    }

    zend_function *function;
    zend_string *fnname;
    ZEND_HASH_FOREACH_STR_KEY_PTR(&ce->function_table, fnname, function) {
        zai_hook_resolve(method_table, function, fnname);
    } ZEND_HASH_FOREACH_END();

    // note: no pDestructor handling needed: zai_hook_resolve empties the table for us
    zend_hash_del(&zai_hook_request_classes, lcname);
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
    } else if (hooks->resolved) {
        // we only handle the resolved case explicitly here, because this is also triggered during zai_hook_finish
        // unresolved is to be freed on caller side
        zend_hash_index_del(&zai_hook_resolved, zai_hook_install_address(hooks->resolved));
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
zai_hook_continued zai_hook_continue(zend_execute_data *ex, zai_hook_memory_t *memory) {
    zai_hooks_entry *hooks = zai_hook_find(ex);

    if (!hooks) {
        return ZAI_HOOK_SKIP;
    }

    size_t dynamic_size = hooks->dynamic;
    zai_hook_memory_allocate(memory, dynamic_size);

    // clang-format off
    // iterate the array in a safe way, i.e. handling possible updates at runtime
    HashPosition pos;
    zend_hash_internal_pointer_reset_ex(&hooks->hooks, &pos);
    uint32_t ht_iter = zend_hash_iterator_add(&hooks->hooks, pos);

    for (zai_hook_t *hook; (hook = zend_hash_get_current_data_ptr_ex(&hooks->hooks, &pos));) {
        zend_hash_move_forward_ex(&hooks->hooks, &pos);

        ++hook->invocation_refcount;
        if (!hook->begin || hook->deleted_invocation != (zend_ulong)-1) {
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

        // increase dynamic memory if new hooks get added during iteration
        if (UNEXPECTED(dynamic_size != hooks->dynamic)) {
            if (dynamic_size < hooks->dynamic) {
                memory->dynamic = erealloc(memory->dynamic, hooks->dynamic);
                memset(memory->dynamic + dynamic_size, 0, hooks->dynamic - dynamic_size);
            }
            dynamic_size = hooks->dynamic;
        }
    }

    zend_hash_iterator_del(ht_iter);
    // clang-format on

    memory->invocation = ++zai_hook_invocation;
    return ZAI_HOOK_CONTINUED;

__zai_hook_finish:
    memory->invocation = ++zai_hook_invocation;
    zai_hook_finish(ex, NULL, memory);
    return ZAI_HOOK_BAILOUT;
} /* }}} */

void zai_hook_generator_resumption(zend_execute_data *ex, zval *sent, zai_hook_memory_t *memory) {
    zai_hooks_entry *hooks = zai_hook_find(ex);

    if (!hooks) {
        return;
    }

    // clang-format off
    HashPosition pos;
    zend_hash_internal_pointer_reset_ex(&hooks->hooks, &pos);
    uint32_t ht_iter = zend_hash_iterator_add(&hooks->hooks, pos);

    for (zai_hook_t *hook; (hook = zend_hash_get_current_data_ptr_ex(&hooks->hooks, &pos));) {
        zend_hash_move_forward_ex(&hooks->hooks, &pos);

        if (!hook->generator_resume || hook->added_invocation >= memory->invocation || hook->deleted_invocation < memory->invocation) {
            continue;
        }

        EG(ht_iterators)[ht_iter].pos = pos;

        hook->generator_resume(
            ex, sent,
            hook->aux.data,
            zai_hook_memory_dynamic(memory, hook));

        pos = zend_hash_iterator_pos(ht_iter, &hooks->hooks);
    }

    zend_hash_iterator_del(ht_iter);
} /* }}} */

void zai_hook_generator_yielded(zend_execute_data *ex, zval *key, zval *yielded, zai_hook_memory_t *memory) {
    zai_hooks_entry *hooks = zai_hook_find(ex);

    if (!hooks) {
        return;
    }

    // clang-format off
    HashPosition pos;
    zend_hash_internal_pointer_reset_ex(&hooks->hooks, &pos);
    uint32_t ht_iter = zend_hash_iterator_add(&hooks->hooks, pos);

    for (zai_hook_t *hook; (hook = zend_hash_get_current_data_ptr_ex(&hooks->hooks, &pos));) {
        zend_hash_move_forward_ex(&hooks->hooks, &pos);

        if (!hook->generator_yield || hook->added_invocation >= memory->invocation || hook->deleted_invocation < memory->invocation) {
            continue;
        }

        EG(ht_iterators)[ht_iter].pos = pos;

        hook->generator_yield(
            ex, key, yielded,
            hook->aux.data,
            zai_hook_memory_dynamic(memory, hook));

        pos = zend_hash_iterator_pos(ht_iter, &hooks->hooks);
    }

    zend_hash_iterator_del(ht_iter);
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
    // iterating in reverse order to properly have LIFO style, and most importantly, zai_hook_remove_from_entry will change the memory offset of any hook coming after it, thus no hooks after it must be called afterwards
    zend_hash_internal_pointer_end_ex(&hooks->hooks, &pos);
    uint32_t ht_iter = zend_hash_iterator_add(&hooks->hooks, pos);

    for (zai_hook_t *hook; (hook = zend_hash_get_current_data_ptr_ex(&hooks->hooks, &pos));) {
        zval key_zv;
        zend_hash_get_current_key_zval_ex(&hooks->hooks, &key_zv, &pos);

        if (hook->added_invocation >= memory->invocation) {
            zend_hash_move_backwards_ex(&hooks->hooks, &pos);
            continue;
        }

        EG(ht_iterators)[ht_iter].pos = pos;

        if (hook->end && hook->deleted_invocation >= memory->invocation) {
            hook->end(
                    ex, rv,
                    hook->aux.data,
                    zai_hook_memory_dynamic(memory, hook));
        }

        if (--hook->invocation_refcount == 0 && hook->deleted_invocation <= zai_hook_invocation) {
            zai_hook_remove_from_entry(hooks, Z_LVAL(key_zv));
        }

        if (EG(ht_iterators)[ht_iter].ht != &hooks->hooks) {
            break;  // ht was deleted
        }

        pos = zend_hash_iterator_pos(ht_iter, &hooks->hooks);
        if (pos >= hooks->hooks.nNumUsed) {
            zend_hash_internal_pointer_end_ex(&hooks->hooks, &pos);
        } else {
            zend_hash_move_backwards_ex(&hooks->hooks, &pos);
        }
    }

    zend_hash_iterator_del(ht_iter);
    // clang-format on

    zai_hook_memory_free(memory);
} /* }}} */

/* {{{ */
bool zai_hook_minit(void) {
    zend_hash_init(&zai_hook_static, 8, NULL, zai_hook_static_destroy, 1);
    return true;
}

bool zai_hook_rinit(void) {
    zend_hash_init(&zai_hook_request_functions, 8, NULL, zai_hook_hash_destroy, 0);
    zend_hash_init(&zai_hook_request_classes, 8, NULL, zai_hook_hash_destroy, 0);
    zend_hash_init(&zai_hook_resolved, 8, NULL, zai_hook_hash_destroy, 0);

    zai_hook_id = 0;

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
        .generator_resume = NULL,
        .generator_yield = NULL,
        .end = end,
        .aux = aux,
        .dynamic = dynamic,
        .dynamic_offset = 0,
        .deleted_invocation = (zend_ulong)-1,
        .invocation_refcount = 0,
    };

    return zai_hook_resolved_install(hook, function);
} /* }}} */

static zend_string *zai_zend_string_init_lower(const char *ptr, size_t len, bool persistent) {
    zend_string *str = zend_string_alloc(len, persistent);
    zend_str_tolower_copy(ZSTR_VAL(str), ptr, len);
    return str;
}

/* {{{ */
zend_long zai_hook_install_generator(
        zai_string_view scope,
        zai_string_view function,
        zai_hook_begin begin,
        zai_hook_generator_resume resumption,
        zai_hook_generator_yield yield,
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
        .scope = scope.len ? zai_zend_string_init_lower(scope.ptr, scope.len, persistent) : NULL,
        .function = zai_zend_string_init_lower(function.ptr, function.len, persistent),
        .begin = begin,
        .generator_resume = resumption,
        .generator_yield = yield,
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
        return zai_hook_static.nNextFreeElement - 1;
    } else {
        return zai_hook_request_install(hook);
    }

}

zend_long zai_hook_install(
        zai_string_view scope,
        zai_string_view function,
        zai_hook_begin begin,
        zai_hook_end end,
        zai_hook_aux aux,
        size_t dynamic) {
    return zai_hook_install_generator(scope, function, begin, NULL, NULL, end, aux, dynamic);
} /* }}} */

static void zai_hooks_try_remove_entry(zai_hooks_entry *hooks, zend_long index) {
    zai_hook_t *hook = zend_hash_index_find_ptr(&hooks->hooks, (zend_ulong)index);
    if (!hook || hook->deleted_invocation <= zai_hook_invocation) {
        return;
    }

    if (((hook->begin && hook->end) || hook->generator_yield || hook->generator_resume) && hook->invocation_refcount > 0) {
        // we have an active hook. We cannot remove it right here, but need to schedule it for deletion
        hook->deleted_invocation = zai_hook_invocation;
    } else {
        zai_hook_remove_from_entry(hooks, (zend_ulong)index);
    }
}

void zai_hook_remove_resolved(zend_function *function, zend_long index) {
    zai_hooks_entry *hooks = zend_hash_index_find_ptr(&zai_hook_resolved, zai_hook_install_address(function));
    if (hooks) {
        zai_hooks_try_remove_entry(hooks, index);
    }
}

void zai_hook_remove(zai_string_view scope, zai_string_view function, zend_long index) {
    zend_function *resolved = zai_hook_lookup_function(scope, function);
    if (resolved) {
        zai_hook_remove_resolved(resolved, index);
        return;
    }

    HashTable *base_ht;
    if (scope.len) {
        base_ht = zend_hash_str_find_ptr_lc(&zai_hook_request_classes, scope.ptr, scope.len);
        if (!base_ht) {
            return;
        }
    } else {
        base_ht = &zai_hook_request_functions;
    }
    zai_hooks_entry *hooks = zend_hash_str_find_ptr_lc(base_ht, function.ptr, function.len);
    if (hooks) {
        zai_hooks_try_remove_entry(hooks, index);
        if (zend_hash_num_elements(&hooks->hooks) == 0) {
            zend_hash_str_del(base_ht, function.ptr, function.len);
            if (zend_hash_num_elements(base_ht) == 0 && hooks->resolved->common.scope) {
                zend_hash_str_del(&zai_hook_request_classes, scope.ptr, scope.len);
            }
        }
    }
}

void zai_hook_clean(void) {
    zai_hooks_entry *hooks;
    ZEND_HASH_REVERSE_FOREACH_PTR(&zai_hook_resolved, hooks) {
        zend_long index;
        ZEND_HASH_FOREACH_NUM_KEY(&hooks->hooks, index) {
            bool last = zend_hash_num_elements(&hooks->hooks);
            zai_hooks_try_remove_entry(hooks, index);
            if (last) {
                break;
            }
        } ZEND_HASH_FOREACH_END();
    } ZEND_HASH_FOREACH_END();
    zend_hash_clean(&zai_hook_request_functions);
    zend_hash_clean(&zai_hook_request_classes);
}

// clang-format on

static void zai_hook_iterator_set_current_and_advance(zai_hook_iterator *it) {
    HashPosition pos = zend_hash_iterator_pos(it->iterator.iter, it->iterator.ht);
    zai_hook_t *hook = zend_hash_get_current_data_ptr_ex(it->iterator.ht, &pos);
    if (hook) {
        zend_hash_get_current_key_ex(it->iterator.ht, NULL, &it->index, &pos);
        it->begin = &hook->begin;
        it->generator_resume = &hook->generator_resume;
        it->generator_yield = &hook->generator_yield;
        it->end = &hook->end;
        it->aux = &hook->aux;
        zend_hash_move_forward_ex(it->iterator.ht, &pos);
        EG(ht_iterators)[it->iterator.iter].pos = pos;
    } else {
        it->active = false;
    }
}

static zai_hook_iterator zai_hook_iterator_init(HashTable *hooks) {
    if (hooks && zend_hash_num_elements(hooks)) {
        zai_hook_iterator it;
        HashPosition pos;
        zend_hash_internal_pointer_reset_ex(hooks, &pos);
        it.iterator.ht = hooks;
        it.iterator.iter = zend_hash_iterator_add(hooks, pos);
        it.active = true;
        zai_hook_iterator_set_current_and_advance(&it);
        return it;
    } else {
        return (zai_hook_iterator){0};
    }
}

zai_hook_iterator zai_hook_iterate_installed(zai_string_view scope, zai_string_view function) {
    zend_function *resolved = zai_hook_lookup_function(scope, function);
    if (resolved) {
        return zai_hook_iterate_resolved(resolved);
    }
    
    HashTable *base_ht;
    if (scope.len) {
        base_ht = zend_hash_str_find_ptr(&zai_hook_request_classes, scope.ptr, scope.len);
        if (!base_ht) {
            return (zai_hook_iterator){0};
        }
    } else {
        base_ht = &zai_hook_request_functions;
    }
    return zai_hook_iterator_init(zend_hash_str_find_ptr(base_ht, function.ptr, function.len));
}

zai_hook_iterator zai_hook_iterate_resolved(zend_function *function) {
    return zai_hook_iterator_init(zend_hash_index_find_ptr(&zai_hook_resolved, zai_hook_install_address(function)));
}

void zai_hook_iterator_advance(zai_hook_iterator *iterator) {
    if (EG(ht_iterators)[iterator->iterator.iter].ht != iterator->iterator.ht) {
        iterator->active = false;
        return;
    }

    zai_hook_iterator_set_current_and_advance(iterator);
}

void zai_hook_iterator_free(zai_hook_iterator *iterator) {
    if (iterator->iterator.ht) {
        zend_hash_iterator_del(iterator->iterator.iter);
    }
}
