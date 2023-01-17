#include <hook/hook.h>
#include <hook/table.h>

/* {{{ */
typedef struct {
    zend_string *scope;
    zend_string *function;
    zend_class_entry *resolved_scope;
    zai_hook_begin begin;
    zai_hook_generator_resume generator_resume;
    zai_hook_generator_yield generator_yield;
    zai_hook_end end;
    zai_hook_aux aux;
    size_t dynamic;
    bool is_global;
    zend_long id;
    int refcount; // one ref held by the existence in the array, one ref held by each frame
} zai_hook_t; /* }}} */

typedef struct {
    HashTable hooks;
    size_t dynamic;
    zend_function *resolved;
} zai_hooks_entry;

typedef struct {
    zai_hook_t *hook;
    size_t dynamic_offset;
} zai_hook_info;

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
static void zai_hook_on_update_empty(zend_function *func, bool remove) { (void)func, (void)remove; }
void (*zai_hook_on_update)(zend_function *func, bool remove) = zai_hook_on_update_empty;
void zai_hook_on_function_resolve_empty(zend_function *func) { (void)func; }
void (*zai_hook_on_function_resolve)(zend_function *func) = zai_hook_on_function_resolve_empty;
#endif

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


static void zai_hook_entries_destroy(zval *zv) {
    zai_hooks_entry *hooks = Z_PTR_P(zv);

#if PHP_VERSION_ID >= 80000
    if (hooks->resolved
#if PHP_VERSION_ID < 80200
        && hooks->resolved->type == ZEND_USER_FUNCTION
#endif
    ) {
        zai_hook_on_update(hooks->resolved, true);
    }
#endif

    zend_hash_iterators_remove(&hooks->hooks);
    zend_hash_destroy(&hooks->hooks);

    efree(hooks);
}

static void zai_hook_hash_destroy(zval *zv) {
    HashTable *hooks = Z_PTR_P(zv);

    zend_hash_iterators_remove(hooks);
    zend_hash_destroy(hooks);

    efree(hooks);
}

/* {{{ */
static inline zend_function *zai_hook_lookup_function(zai_string_view scope, zai_string_view func, zend_class_entry **ce) {
    zend_function *function = NULL;

    if (scope.len) {
        *ce = zai_symbol_lookup_class(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &scope);

        if (!*ce) {
            /* class not available */
            return NULL;
        }
        function = zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_CLASS, *ce, &func);
    } else {
        ce = NULL;
        function = zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &func);
    }
    return function;
}

static void zai_hook_sort_newest(zai_hooks_entry *hooks) {
    if (hooks->resolved->common.scope) {
        HashPosition pos;
        zend_hash_internal_pointer_end_ex(&hooks->hooks, &pos);
        Bucket *newBucket = &hooks->hooks.arData[pos];
        zai_hook_t *hook = Z_PTR(newBucket->val);
        // traits are always last
        if (hook->resolved_scope->ce_flags & ZEND_ACC_TRAIT) {
            return;
        }

        // sort: children at the start, parents at the end
        HashPosition prevPos = pos;
        zend_hash_move_backwards_ex(&hooks->hooks, &prevPos);
        while (prevPos < hooks->hooks.nNumUsed) {
            Bucket *bucket = &hooks->hooks.arData[prevPos];

            zai_hook_t *check = Z_PTR(bucket->val);
            // Everything except the newest entry is already properly sorted
            if (instanceof_function(check->resolved_scope, hook->resolved_scope)) {
                // we're iterating from the end, i.e. first the top/parent of the hierarchy.
                // Once the class is instanceof our hooked class, all further classes are either children or in unrelated hierarchies
                // prevPos is the last matching one. Increment to have index 0 or the first one to move respectively.
                ++prevPos;
                goto move;
            }

            zend_hash_move_backwards_ex(&hooks->hooks, &prevPos);
        }
        prevPos = 0;

move: ;
        // prevPos is now the index where the new entry will be spliced in
        if (pos != prevPos) {
            for (int32_t i = -1; i > -(int32_t)hooks->hooks.nTableSize; --i) {
                uint32_t *hash = &HT_HASH(&hooks->hooks, HT_IDX_TO_HASH(i));
                if (*(int32_t*)hash >= (int32_t)prevPos) {
                    if (*hash == pos) {
                        *hash = prevPos;
                    } else {
                        ++*hash;
                    }
                }
            }

            for (uint32_t i = 0; i < hooks->hooks.nNumUsed; ++i) {
                uint32_t *hash = &Z_NEXT(hooks->hooks.arData[i].val);
                if (*(int32_t*)hash >= (int32_t)prevPos) {
                    if (*hash == pos) {
                        *hash = prevPos;
                    } else {
                        ++*hash;
                    }
                }
            }

            Bucket tmp = *newBucket;
            Bucket *target = &hooks->hooks.arData[prevPos];
            memmove(target + 1, target, (char*)newBucket - (char *)target);
            *target = tmp;

            HashTableIterator *iter = EG(ht_iterators);
            HashTableIterator *end  = iter + EG(ht_iterators_used);

            while (iter != end) {
                if (iter->ht == &hooks->hooks && (int32_t)iter->pos >= (int32_t)prevPos) {
                    ++iter->pos;
                }
                iter++;
            }
        }
    }
}

static zend_long zai_hook_add_entry(zai_hooks_entry *hooks, zai_hook_t *hook) {
    zend_ulong index = ++zai_hook_id;
    if (!zend_hash_index_add_ptr(&hooks->hooks, index, hook)) {
        // handle the edge case where a static hook is re-inserted after tracer shutdown and re-startup
        hook->id = (zend_long)index;
        return hook->id;
    }

    if (zend_hash_num_elements(&hooks->hooks) > 1 && hooks->resolved) {
        zai_hook_sort_newest(hooks);
    }

    hooks->dynamic += hook->dynamic;

    return (zend_long)index;
}

static zai_hooks_entry *zai_hook_alloc_hooks_entry(zend_function *resolved) {
    zai_hooks_entry *hooks = emalloc(sizeof(*hooks));
    hooks->dynamic = 0;
    hooks->resolved = resolved;
    zend_hash_init(&hooks->hooks, 8, NULL, zai_hook_destroy, 0);
    hooks->hooks.nNextFreeElement = ZEND_LONG_MAX >> 1;
    return hooks;
}

static zend_long zai_hook_resolved_install(zai_hook_t *hook, zend_function *resolved) {
    zai_install_address addr = zai_hook_install_address(resolved);
    zai_hooks_entry *hooks = zend_hash_index_find_ptr(&zai_hook_resolved, addr);
    if (!hooks) {
        hooks = zai_hook_alloc_hooks_entry(resolved);
        zend_hash_index_add_ptr(&zai_hook_resolved, addr, hooks);

#if PHP_VERSION_ID >= 80000
#if PHP_VERSION_ID < 80200
        if (hooks->resolved->type == ZEND_USER_FUNCTION)
#endif
        {
            zai_hook_on_update(hooks->resolved, false);
        }
#endif
    }

    return zai_hook_add_entry(hooks, hook);
}

static zend_long zai_hook_request_install(zai_hook_t *hook) {
    zend_class_entry *ce = NULL;
    zai_string_view scope = hook->scope ? ZAI_STRING_FROM_ZSTR(hook->scope) : ZAI_STRING_EMPTY;
    zend_function *function = zai_hook_lookup_function(scope, ZAI_STRING_FROM_ZSTR(hook->function), &ce);
    if (function) {
        hook->resolved_scope = ce;
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
        hooks = zai_hook_alloc_hooks_entry(NULL);
        zend_hash_add_ptr(funcs, hook->function, hooks);
    }

    return zai_hook_add_entry(hooks, hook);
}

static inline void zai_hook_resolve(HashTable *base_ht, zend_class_entry *ce, zend_function *function, zend_string *lcname) {
#if PHP_VERSION_ID >= 80000
    if (function->common.scope == ce) {
        zai_hook_on_function_resolve(function);
    }
#endif

    zai_hooks_entry *hooks = zend_hash_find_ptr(base_ht, lcname);
    if (!hooks) {
        return;
    }

    zai_install_address addr = zai_hook_install_address(function);
    if (!zend_hash_index_add_ptr(&zai_hook_resolved, addr, hooks)) {
        // it's already there (e.g. thanks to aliases, traits, ...), merge it
        zai_hooks_entry *existingHooks = zend_hash_index_find_ptr(&zai_hook_resolved, addr);
        zval *hook_zv;
        zend_ulong index;
        ZEND_HASH_FOREACH_NUM_KEY_VAL(&hooks->hooks, index, hook_zv) {
            zai_hook_t *hook = Z_PTR_P(hook_zv);
            hook->resolved_scope = ce;
            existingHooks->dynamic += hook->dynamic;
            zend_hash_index_add_new(&existingHooks->hooks, index, hook_zv);
            zai_hook_sort_newest(existingHooks);
        } ZEND_HASH_FOREACH_END();

        // we remove the whole zai_hooks_entry, excluding the individual zai_hook_t which we moved
        hooks->hooks.pDestructor = NULL;
        zend_hash_del(base_ht, lcname);
    } else {
        // we remove the function entry in the base table, but preserve the zai_hooks_entry
        base_ht->pDestructor = NULL;
        zend_hash_del(base_ht, lcname);
        base_ht->pDestructor = zai_hook_hash_destroy;
        hooks->resolved = function;
        zai_hook_t *hook;
        ZEND_HASH_FOREACH_PTR(&hooks->hooks, hook) {
            hook->resolved_scope = ce;
        } ZEND_HASH_FOREACH_END();
    }
}

/* {{{ */
void zai_hook_resolve_function(zend_function *function, zend_string *lcname) {
    zai_hook_resolve(&zai_hook_request_functions, NULL, function, lcname);
}

void zai_hook_resolve_class(zend_class_entry *ce, zend_string *lcname) {
    zend_function *function;

    HashTable *method_table = zend_hash_find_ptr(&zai_hook_request_classes, lcname);
    if (!method_table) {
#if PHP_VERSION_ID >= 80000
        ZEND_HASH_FOREACH_PTR(&ce->function_table, function) {
            if (function->common.scope == ce) {
                zai_hook_on_function_resolve(function);
            }
        } ZEND_HASH_FOREACH_END();
#endif
        return;
    }

    zend_string *fnname;
    ZEND_HASH_FOREACH_STR_KEY_PTR(&ce->function_table, fnname, function) {
        zai_hook_resolve(method_table, ce, function, fnname);
    } ZEND_HASH_FOREACH_END();

    if (zend_hash_num_elements(method_table) == 0) {
        // note: no pDestructor handling needed: zai_hook_resolve empties the table for us
        zend_hash_del(&zai_hook_request_classes, lcname);
    }
}

static bool zai_hook_remove_from_entry(zai_hooks_entry *hooks, zend_ulong index) {
    zai_hook_t *hook = zend_hash_index_find_ptr(&hooks->hooks, index);

    if (!hook || hook->id < 0) {
        return false;
    }

    hooks->dynamic -= hook->dynamic;
    if (!--hook->refcount) {
        zend_hash_index_del(&hooks->hooks, index);
    } else {
        hook->id = -hook->id;
    }

    return true;
}

/* {{{ */
zai_hook_continued zai_hook_continue(zend_execute_data *ex, zai_hook_memory_t *memory) {
    zai_hooks_entry *hooks;

    if (!zai_hook_table_find(&zai_hook_resolved, zai_hook_frame_address(ex), (void**)&hooks)) {
        return ZAI_HOOK_SKIP;
    }

    int allocated_hook_count = zend_hash_num_elements(&hooks->hooks);
    size_t hook_info_size = allocated_hook_count * sizeof(zai_hook_info);
    size_t dynamic_size = hooks->dynamic + hook_info_size;
    // a vector of first N hook_info entries, then N entries of variable size (as much memory as the individual hooks require)
    memory->dynamic = ecalloc(1, dynamic_size);
    memory->invocation = ++zai_hook_invocation;

    // iterate the array in a safe way, i.e. handling possible updates at runtime
    HashPosition pos;
    zend_hash_internal_pointer_reset_ex(&hooks->hooks, &pos);
    uint32_t ht_iter = zend_hash_iterator_add(&hooks->hooks, pos);
    int hook_num = 0;
    size_t dynamic_offset = hook_info_size;
    bool check_scope = ex->func->common.scope != NULL;

    for (zai_hook_t *hook; (hook = zend_hash_get_current_data_ptr_ex(&hooks->hooks, &pos));) {
        zend_hash_move_forward_ex(&hooks->hooks, &pos);

        if (hook->id < 0) {
            continue;
        }

        if (check_scope) {
            if (!(hook->resolved_scope->ce_flags & ZEND_ACC_TRAIT) && !instanceof_function(zend_get_called_scope(ex), hook->resolved_scope)) {
                continue;
            }
        }

        // increase dynamic memory if new hooks get added during iteration
        if (UNEXPECTED(dynamic_offset + hook->dynamic > dynamic_size || allocated_hook_count <= hook_num)) {
            for (int i = 0; i < hook_num; ++i) {
                ((zai_hook_info *)memory->dynamic)[i].dynamic_offset += sizeof(zai_hook_info);
            }
            dynamic_offset += sizeof(zai_hook_info);

            size_t new_hook_info_size = ++allocated_hook_count * sizeof(zai_hook_info);
            size_t new_dynamic_size = dynamic_offset + hook->dynamic - hook_info_size + new_hook_info_size;
            if (new_dynamic_size > dynamic_size) {
                memory->dynamic = erealloc(memory->dynamic, new_dynamic_size);
            }
            // Create some space for zai_hook_info entries in between, and some new dynamic memory at the end
            memmove(memory->dynamic + new_hook_info_size, memory->dynamic + hook_info_size, dynamic_size - hook_info_size);
            if (new_dynamic_size > dynamic_size) {
                // and ensure the new dynamic memory is zeroed
                size_t hook_info_size_delta = new_hook_info_size - hook_info_size;
                memset(memory->dynamic + dynamic_size + hook_info_size_delta, 0, new_dynamic_size - dynamic_size - hook_info_size_delta);
                dynamic_size = new_dynamic_size;
            }
            hook_info_size = new_hook_info_size;
        }

        ((zai_hook_info *)memory->dynamic)[hook_num++] = (zai_hook_info){ .hook = hook, .dynamic_offset = dynamic_offset };

        ++hook->refcount;
        if (!hook->begin) {
            continue;
        }

        EG(ht_iterators)[ht_iter].pos = pos;

        if (!hook->begin(memory->invocation, ex, hook->aux.data, memory->dynamic + dynamic_offset)) {
            zend_hash_iterator_del(ht_iter);

            memory->hook_count = hook_num;
            zai_hook_finish(ex, NULL, memory);
            return ZAI_HOOK_BAILOUT;
        }

        if (UNEXPECTED(EG(ht_iterators)[ht_iter].ht != &hooks->hooks)) { // ht was deleted
            if (!zai_hook_table_find(&zai_hook_resolved, zai_hook_frame_address(ex), (void**)&hooks)) {
                break; // and was not recreated
            }

            zend_hash_iterator_del(ht_iter);
            zend_hash_internal_pointer_reset_ex(&hooks->hooks, &pos);
            ht_iter = zend_hash_iterator_add(&hooks->hooks, pos);
        }
        pos = zend_hash_iterator_pos(ht_iter, &hooks->hooks);

        dynamic_offset += hook->dynamic;
    }

    zend_hash_iterator_del(ht_iter);

    memory->hook_count = hook_num;
    return ZAI_HOOK_CONTINUED;
} /* }}} */

void zai_hook_generator_resumption(zend_execute_data *ex, zval *sent, zai_hook_memory_t *memory) {
    for (zai_hook_info *hook_info = memory->dynamic, *hook_end = hook_info + memory->hook_count; hook_info < hook_end; ++hook_info) {
        zai_hook_t *hook = hook_info->hook;

        if (!hook->generator_resume) {
            continue;
        }

        hook->generator_resume(memory->invocation, ex, sent, hook->aux.data, memory->dynamic + hook_info->dynamic_offset);
    }
} /* }}} */

void zai_hook_generator_yielded(zend_execute_data *ex, zval *key, zval *yielded, zai_hook_memory_t *memory) {
    for (zai_hook_info *hook_start = memory->dynamic, *hook_info = hook_start + memory->hook_count - 1; hook_info >= hook_start; --hook_info) {
        zai_hook_t *hook = hook_info->hook;

        if (!hook->generator_yield) {
            continue;
        }

        hook->generator_yield(memory->invocation, ex, key, yielded, hook->aux.data, memory->dynamic + hook_info->dynamic_offset);
    }
} /* }}} */

/* {{{ */
void zai_hook_finish(zend_execute_data *ex, zval *rv, zai_hook_memory_t *memory) {
    // iterating in reverse order to properly have LIFO style
    if (!memory->dynamic) {
        return;
    }

    for (zai_hook_info *hook_start = memory->dynamic, *hook_info = hook_start + memory->hook_count - 1; hook_info >= hook_start; --hook_info) {
        zai_hook_t *hook = hook_info->hook;

        if (hook->end) {
            hook->end(memory->invocation, ex, rv, hook->aux.data, memory->dynamic + hook_info->dynamic_offset);
        }

        if (!--hook->refcount) {
            zai_hooks_entry *hooks = NULL;
            zai_hook_table_find(&zai_hook_resolved, zai_hook_frame_address(ex), (void**)&hooks);
            zend_hash_index_del(&hooks->hooks, (zend_ulong) -hook->id);
            if (zend_hash_num_elements(&hooks->hooks) == 0) {
                zend_hash_index_del(&zai_hook_resolved, zai_hook_install_address(hooks->resolved));
            }
        }
    }

    efree(memory->dynamic);

    memory->dynamic = NULL;
} /* }}} */

/* {{{ */
bool zai_hook_minit(void) {
    zend_hash_init(&zai_hook_static, 8, NULL, zai_hook_static_destroy, 1);
    zai_hook_static.nNextFreeElement = 1;
    return true;
}

bool zai_hook_rinit(void) {
    zend_hash_init(&zai_hook_request_functions, 8, NULL, zai_hook_hash_destroy, 0);
    zend_hash_init(&zai_hook_request_classes, 8, NULL, zai_hook_hash_destroy, 0);
    zend_hash_init(&zai_hook_resolved, 8, NULL, zai_hook_entries_destroy, 0);

    // reserve low hook ids for static hooks
    zai_hook_id = (zend_ulong)zai_hook_static.nNextFreeElement;

    return true;
}

void zai_hook_activate(void) {
    zend_ulong current_hook_id = zai_hook_id;
    zai_hook_id = 0;

    zai_hook_t *hook;
    ZEND_HASH_FOREACH_PTR(&zai_hook_static, hook) {
        zai_hook_t *copy = emalloc(sizeof(*copy));
        *copy = *hook;
        copy->is_global = true;
        zai_hook_request_install(copy);
    } ZEND_HASH_FOREACH_END();

    zai_hook_id = current_hook_id;
}

void zai_hook_rshutdown(void) {
    // freeing this after a bailout is a bad idea: at least resolved hooks will contain objects, which are invalid when destroyed here.
    if (!CG(unclean_shutdown)) {
        zend_hash_destroy(&zai_hook_resolved);
        zend_hash_destroy(&zai_hook_request_functions);
        zend_hash_destroy(&zai_hook_request_classes);
    }
}

void zai_hook_mshutdown(void) { zend_hash_destroy(&zai_hook_static); } /* }}} */

/* {{{ */
zend_long zai_hook_install_resolved_generator(zend_function *function,
        zai_hook_begin begin, zai_hook_generator_resume resumption, zai_hook_generator_yield yield, zai_hook_end end,
        zai_hook_aux aux, size_t dynamic) {
    if (!PG(modules_activated)) {
        /* not allowed: can only do resolved install during request */
        return false;
    }

    zai_hook_t *hook = emalloc(sizeof(*hook));
    *hook = (zai_hook_t){
        .scope = NULL,
        .function = NULL,
        .resolved_scope = function->common.scope,
        .begin = begin,
        .generator_resume = resumption,
        .generator_yield = yield,
        .end = end,
        .aux = aux,
        .is_global = false,
        .id = 0,
        .dynamic = dynamic,
        .refcount = 1,
    };

    return hook->id = zai_hook_resolved_install(hook, function);
} /* }}} */

zend_long zai_hook_install_resolved(zend_function *function,
        zai_hook_begin begin, zai_hook_end end,
        zai_hook_aux aux, size_t dynamic) {
    return zai_hook_install_resolved_generator(function, begin, NULL, NULL, end, aux, dynamic);
}

static zend_string *zai_zend_string_init_lower(const char *ptr, size_t len, bool persistent) {
    zend_string *str = zend_string_alloc(len, persistent);
    zend_str_tolower_copy(ZSTR_VAL(str), ptr, len);
    if (persistent) {
        str = zend_new_interned_string(str);
    }
    return str;
}

/* {{{ */
zend_long zai_hook_install_generator(zai_string_view scope, zai_string_view function,
        zai_hook_begin begin, zai_hook_generator_resume resumption, zai_hook_generator_yield yield, zai_hook_end end,
        zai_hook_aux aux, size_t dynamic) {
    if (!function.len) {
        /* not allowed: target must be known */
        if (aux.dtor) {
            aux.dtor(aux.data);
        }
        return -1;
    }

    bool persistent = !PG(modules_activated);

    zai_hook_t *hook = pemalloc(sizeof(*hook), persistent);
    *hook = (zai_hook_t){
        .scope = scope.len ? zai_zend_string_init_lower(scope.ptr, scope.len, persistent) : NULL,
        .function = zai_zend_string_init_lower(function.ptr, function.len, persistent),
        .resolved_scope = NULL,
        .begin = begin,
        .generator_resume = resumption,
        .generator_yield = yield,
        .end = end,
        .aux = aux,
        .is_global = false,
        .id = 0,
        .dynamic = dynamic,
        .refcount = 1,
    };

    if (persistent) {
        zend_hash_next_index_insert_ptr(&zai_hook_static, hook);
        return hook->id = zai_hook_static.nNextFreeElement - 1;
    } else {
        return hook->id = zai_hook_request_install(hook);
    }
}

zend_long zai_hook_install(zai_string_view scope, zai_string_view function,
        zai_hook_begin begin, zai_hook_end end,
        zai_hook_aux aux, size_t dynamic) {
    return zai_hook_install_generator(scope, function, begin, NULL, NULL, end, aux, dynamic);
} /* }}} */

bool zai_hook_remove_resolved(zai_install_address function_address, zend_long index) {
    zai_hooks_entry *hooks = zend_hash_index_find_ptr(&zai_hook_resolved, function_address);
    if (hooks) {
        if (!zai_hook_remove_from_entry(hooks, (zend_ulong)index)) {
            return false;
        }

        if (zend_hash_num_elements(&hooks->hooks) == 0) {
            zend_hash_index_del(&zai_hook_resolved, zai_hook_install_address(hooks->resolved));
        }

        return true;
    }
    return false;
}

bool zai_hook_remove(zai_string_view scope, zai_string_view function, zend_long index) {
    zend_class_entry *ce;
    zend_function *resolved = zai_hook_lookup_function(scope, function, &ce);
    if (resolved) {
        return zai_hook_remove_resolved(zai_hook_install_address(resolved), index);
    }

    HashTable *base_ht;
    if (scope.len) {
        base_ht = zend_hash_str_find_ptr_lc(&zai_hook_request_classes, scope.ptr, scope.len);
        if (!base_ht) {
            return false;
        }
    } else {
        base_ht = &zai_hook_request_functions;
    }
    zai_hooks_entry *hooks = zend_hash_str_find_ptr_lc(base_ht, function.ptr, function.len);
    if (hooks) {
        if (!zai_hook_remove_from_entry(hooks, (zend_ulong)index)) {
            return false;
        }

        if (zend_hash_num_elements(&hooks->hooks) == 0) {
            zend_hash_str_del(base_ht, function.ptr, function.len);
            if (zend_hash_num_elements(base_ht) == 0 && scope.len) {
                zend_hash_str_del(&zai_hook_request_classes, scope.ptr, scope.len);
            }
        }

        return true;
    }

    return false;
}

void zai_hook_clean(void) {
    zend_hash_clean(&zai_hook_resolved);
    zend_hash_clean(&zai_hook_request_functions);
    zend_hash_clean(&zai_hook_request_classes);
}

static void zai_hook_iterator_set_current_and_advance(zai_hook_iterator *it) {
    HashPosition pos = zend_hash_iterator_pos(it->iterator.iter, it->iterator.ht);
    zai_hook_t *hook;
    while ((hook = zend_hash_get_current_data_ptr_ex(it->iterator.ht, &pos)) && hook->id < 0) {
        zend_hash_move_forward_ex(it->iterator.ht, &pos);
    }
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
    zend_class_entry *ce;
    zend_function *resolved = zai_hook_lookup_function(scope, function, &ce);
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
