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
    bool is_abstract;
    zend_long id;
    int refcount; // one ref held by the existence in the array, one ref held by each frame
} zai_hook_t; /* }}} */

typedef struct _zai_hooks_entry {
    HashTable hooks;
    size_t dynamic;
#if PHP_VERSION_ID >= 80000
    // Note: there may be multiple Closures pointing to the same opcodes. These Closures may have different lifetimes and potentially ZEND_ACC_HEAP_RT_CACHE.
    // But to ensure consistency between existence of hooks and Closure actually being hooked, we need to keep track of the run_time_cache, so that we eventually may remove the hook again.
#if PHP_VERSION_ID >= 80200
    struct _zai_hooks_entry *internal_duplicate;
    void **run_time_cache; // used only if Closure
#else
    void ***run_time_cache; // used only if Closure
#endif
    bool is_generator;
    // However, for non-Closure functions, we may not track the run_time_cache, as it may not yet have been initialized when first resolved
#endif
    // On PHP 7, we only go by resolved; we don't have to care about individual rt_caches
    zend_function *resolved; // if non-closure, else NULL
} zai_hooks_entry;

typedef struct {
    zai_hook_t *hook;
    size_t dynamic_offset;
} zai_hook_info;

ZEND_TLS zend_ulong zai_hook_invocation = 0;
ZEND_TLS zend_ulong zai_hook_id;

/* {{{ private tables */
// zai_hook_static is a simple array of persistently allocated zai_hook_t
// these persistently allocated zai_hook_t are always duplicated (with is_global = true) into zai_hook_request_* on request start
static HashTable zai_hook_static;

// zai_hook_request_functions is a map name -> array<zai_hook_t>
ZEND_TLS HashTable zai_hook_request_functions;
// zai_hook_request_classes is a map class name -> map function name -> array<zai_hook_t>
ZEND_TLS HashTable zai_hook_request_classes;
// zai_hook_request_files is an array<zai_hook_t>
ZEND_TLS zai_hooks_entry zai_hook_request_files;

// zai_hook_resolved is a map op_array/internal_function -> array<zai_hook_t>
// if indirect, then it's pointing to some hashtable in zai_hook_request_functions/classes
TSRM_TLS HashTable zai_hook_resolved;

// zai_hook_inheritors is a map of persistent class entries (interfaces and abstract classes) to a list of persistent class entries
static HashTable zai_hook_static_inheritors;

// zai_hook_inheritors is a map of class entries (interfaces and abstract classes) to a list of class entries
ZEND_TLS HashTable zai_hook_inheritors;

typedef struct {
    size_t size;
    zend_class_entry *inheritor[];
} zai_hook_inheritor_list;

typedef struct {
    uint32_t ordered;
    uint32_t size;
    zend_function *functions[];
} zai_function_location_entry;

// zai_function_location_map maps from a filename to a possibly ordered array of values
ZEND_TLS HashTable zai_function_location_map; /* }}} */

#define ZAI_IS_SHARED_HOOK_PTR (IS_PTR+1)

#if PHP_VERSION_ID >= 80000
static void zai_hook_on_update_empty(zend_function *func, bool remove) { (void)func, (void)remove; }
void (*zai_hook_on_update)(zend_function *func, bool remove) = zai_hook_on_update_empty;
void zai_hook_on_function_resolve_empty(zend_function *func) { (void)func; }
void (*zai_hook_on_function_resolve)(zend_function *func) = zai_hook_on_function_resolve_empty;
#endif

#if PHP_VERSION_ID < 70200
typedef void (*zif_handler)(INTERNAL_FUNCTION_PARAMETERS);
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
    if (Z_TYPE_INFO_P(zv) == ZAI_IS_SHARED_HOOK_PTR) {
        return;
    }

    zai_hook_t *hook = Z_PTR_P(zv);

    if (!hook->is_global) {
        zai_hook_data_dtor(hook);
    }

    efree(hook);
}

// Manually polyfill the poisoning here to avoid https://github.com/php/php-src/issues/8438
static void _zend_hash_iterators_remove(HashTable *ht) {
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


static void zai_hook_static_inheritors_destroy(zval *zv) {
    free(Z_PTR_P(zv));
}

static void zai_hook_inheritors_destroy(zval *zv) {
    efree(Z_PTR_P(zv));
}

static void zai_hook_entries_destroy(zai_hooks_entry *hooks, zend_ulong install_address) {
    if (hooks == &zai_hook_request_files) {
        return;
    }

#if PHP_VERSION_ID >= 80000
    if (hooks->resolved
#if PHP_VERSION_ID < 80200
        && hooks->resolved->type == ZEND_USER_FUNCTION
#endif
    ) {
#if PHP_VERSION_ID >= 80200
        // Internal functions duplicated onto userland classes share their run_time_cache with their parent function - the parent function must be responsible for adding or removing the hooking
        if (!hooks->internal_duplicate)
#endif
        {
            zai_hook_on_update(hooks->resolved, true);
        }
    } else if (hooks->run_time_cache) {
        zend_function func;
        func.common.fn_flags = hooks->is_generator ? ZEND_ACC_GENERATOR : 0;
        func.op_array.opcodes = (void *)(uintptr_t *)(install_address << 5); // does not need to be valid, but sufficient to get install_address
#if PHP_VERSION_ID >= 80200
        ZEND_MAP_PTR_INIT(func.common.run_time_cache, hooks->run_time_cache);
#else
        ZEND_MAP_PTR_INIT(func.op_array.run_time_cache, hooks->run_time_cache);
#endif
        zai_hook_on_update(&func, true);
    }
#else
    (void)install_address;
#endif

    zend_hash_iterators_remove(&hooks->hooks);
    zend_hash_destroy(&hooks->hooks);

    efree(hooks);
}

static void zai_hook_entries_remove_resolved(zend_ulong install_address) {
    zai_hooks_entry *hooks = zend_hash_index_find_ptr(&zai_hook_resolved, install_address);
    if (hooks) {
#if PHP_VERSION_ID >= 80200
        if (hooks->internal_duplicate) {
            // We refcount parents of internal function duplicates, remove here again if necessary
            if (--hooks->internal_duplicate->hooks.nNumOfElements == 0) {
                zai_hook_entries_remove_resolved(zai_hook_install_address(hooks->internal_duplicate->resolved));
            }
        }
#endif
        zai_hook_entries_destroy(hooks, install_address);
        zend_hash_index_del(&zai_hook_resolved, install_address);
    }
}

static void zai_hook_hash_destroy(zval *zv) {
    if (Z_TYPE_P(zv) == ZAI_IS_SHARED_HOOK_PTR) {
        return;
    }

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
            for (int32_t i = -1; i >= (int32_t)hooks->hooks.nTableMask; --i) {
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

static zai_hooks_entry *zai_hook_alloc_hooks_entry(void) {
    zai_hooks_entry *hooks = emalloc(sizeof(*hooks));
    hooks->dynamic = 0;
    hooks->resolved = NULL;
#if PHP_VERSION_ID >= 80000
    hooks->run_time_cache = NULL;
#endif
    zend_hash_init(&hooks->hooks, 8, NULL, zai_hook_destroy, 0);
    hooks->hooks.nNextFreeElement = ZEND_LONG_MAX >> 1;
    return hooks;
}

static void zai_hook_resolve_hooks_entry(zai_hooks_entry *hooks, zend_function *resolved) {
#if PHP_VERSION_ID >= 80000
    if ((resolved->common.fn_flags & ZEND_ACC_HEAP_RT_CACHE) == 0
#if PHP_VERSION_ID < 80200
        && resolved->common.type == ZEND_USER_FUNCTION
#endif
            ) {
#if PHP_VERSION_ID < 80200
        hooks->run_time_cache = ZEND_MAP_PTR(resolved->op_array.run_time_cache);
#else
        hooks->run_time_cache = ZEND_MAP_PTR(resolved->common.run_time_cache);
#endif
    }
    hooks->is_generator = (resolved->common.fn_flags & ZEND_ACC_GENERATOR) != 0;
#endif
    if ((resolved->common.fn_flags & ZEND_ACC_CLOSURE) == 0)
    {
         hooks->resolved = resolved;
    }
}

#if PHP_VERSION_ID >= 80200
static inline zai_hooks_entry *zai_hook_resolved_ensure_hooks_entry(zend_function *resolved, zend_class_entry *ce);
static inline void zai_hook_handle_internal_duplicate_function(zai_hooks_entry *hooks, zend_class_entry *ce, zend_function *function) {
    // Internal functions duplicated onto userland classes share their run_time_cache with their parent function
    bool is_internal_duplicate = !ZEND_USER_CODE(function->type) && (function->common.fn_flags & ZEND_ACC_ARENA_ALLOCATED) && function->common.scope != ce;
    if (is_internal_duplicate) {
        // Hence we need to ensure that each top-level internal function is responsible for its run-time cache.
        // We achieve that by refcounting on top of the anyway checked nNumOfElements.
        zend_string *lcname = zend_string_tolower(function->common.function_name);
        zend_function *original_function = zend_hash_find_ptr(&ce->parent->function_table, lcname);
        zend_string_release(lcname);
        hooks->internal_duplicate = zai_hook_resolved_ensure_hooks_entry(original_function, ce->parent);
        ++hooks->internal_duplicate->hooks.nNumOfElements;
    } else {
        hooks->internal_duplicate = NULL;
    }
}
#endif

static inline zai_hooks_entry *zai_hook_resolved_ensure_hooks_entry(zend_function *resolved, zend_class_entry *ce) {
    zai_install_address addr = zai_hook_install_address(resolved);
    zai_hooks_entry *hooks = zend_hash_index_find_ptr(&zai_hook_resolved, addr);
    if (!hooks) {
        hooks = zai_hook_alloc_hooks_entry();
        zend_hash_index_add_ptr(&zai_hook_resolved, addr, hooks);

#if PHP_VERSION_ID >= 80000
#if PHP_VERSION_ID < 80200
        (void)ce;
        if (resolved->type == ZEND_USER_FUNCTION)
#else
        zai_hook_handle_internal_duplicate_function(hooks, ce, resolved);
        if (!hooks->internal_duplicate)
#endif
        {
            zai_hook_on_update(resolved, false);
        }
#else
        (void)ce;
#endif
    }

    zai_hook_resolve_hooks_entry(hooks, resolved);

    return hooks;
}

static inline void zai_hook_resolved_install_shared_hook(zai_hook_t *hook, zend_ulong index, zend_function *func, zend_class_entry *ce) {
    zai_hooks_entry *hooks = zai_hook_resolved_ensure_hooks_entry(func, ce);

    zval hook_zv;
    Z_TYPE_INFO(hook_zv) = ZAI_IS_SHARED_HOOK_PTR;
    Z_PTR(hook_zv) = hook;
    if (zend_hash_index_add(&hooks->hooks, index, &hook_zv)) {
        if (zend_hash_num_elements(&hooks->hooks) > 1) {
            zai_hook_sort_newest(hooks);
        }

        hooks->dynamic += hook->dynamic;
    }
}

static inline void zai_hook_resolved_install_abstract_recursive(zai_hook_t *hook, zend_ulong index, zend_class_entry *scope) {
    // find implementers by searching through all inheritors, recursively, stopping upon finding a non-abstract implementation
    zai_hook_inheritor_list *inheritors;
    zend_ulong ce_addr = ((zend_ulong)scope) << 3;
    if ((inheritors = zend_hash_index_find_ptr(&zai_hook_inheritors, ce_addr))) {
        for (size_t i = inheritors->size; i--;) {
            zend_class_entry *inheritor = inheritors->inheritor[i];
            zend_function *override = zend_hash_find_ptr(&inheritor->function_table, hook->function);
            if (override) {
                zai_hook_resolved_install_shared_hook(hook, index, override, inheritor);
            }
            if (!override || (override->common.fn_flags & ZEND_ACC_ABSTRACT) != 0) {
                zai_hook_resolved_install_abstract_recursive(hook, index, inheritor);
            }
        }
    }
}

static inline void zai_hook_resolved_install_inherited_internal_function_recursive(zai_hook_t *hook, zend_ulong index, zend_class_entry *scope, zif_handler handler) {
    // find implementers by searching through all inheritors, recursively, stopping upon finding an explicit override
    zai_hook_inheritor_list *inheritors;
    zend_ulong ce_addr = ((zend_ulong)scope) << 3;
    if ((inheritors = zend_hash_index_find_ptr(&zai_hook_inheritors, ce_addr))) {
        for (size_t i = inheritors->size; i--;) {
            zend_class_entry *inheritor = inheritors->inheritor[i];
            zend_function *child_function = zend_hash_find_ptr(&inheritor->function_table, hook->function);
            if (child_function && !ZEND_USER_CODE(child_function->type) && child_function->internal_function.handler == handler) {
                zai_hook_resolved_install_shared_hook(hook, index, child_function, inheritor);
                zai_hook_resolved_install_inherited_internal_function_recursive(hook, index, inheritor, handler);
            }
        }
    }
}

static zend_long zai_hook_resolved_install(zai_hook_t *hook, zend_function *resolved, zend_class_entry *ce) {
    zai_hooks_entry *hooks = zai_hook_resolved_ensure_hooks_entry(resolved, ce);
    zend_long index = zai_hook_add_entry(hooks, hook);

    if (hook->is_abstract) {
        zai_hook_resolved_install_abstract_recursive(hook, (zend_ulong)index, resolved->common.scope);
    } else if (!ZEND_USER_CODE(resolved->type) && resolved->common.scope) {
        zai_hook_resolved_install_inherited_internal_function_recursive(hook, (zend_ulong)index, resolved->common.scope, resolved->internal_function.handler);
    }

    return index;
}

static zend_long zai_hook_request_install(zai_hook_t *hook) {
    if (!hook->function) {
        return zai_hook_add_entry(&zai_hook_request_files, hook);
    }

    zend_class_entry *ce = NULL;
    zai_string_view scope = hook->scope ? ZAI_STRING_FROM_ZSTR(hook->scope) : ZAI_STRING_EMPTY;
    zend_function *function = zai_hook_lookup_function(scope, ZAI_STRING_FROM_ZSTR(hook->function), &ce);
    if (function) {
        hook->resolved_scope = ce;
        hook->is_abstract = (function->common.fn_flags & ZEND_ACC_ABSTRACT) != 0;
        return zai_hook_resolved_install(hook, function, ce);
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
        hooks = zai_hook_alloc_hooks_entry();
        zend_hash_add_ptr(funcs, hook->function, hooks);
    }

    return zai_hook_add_entry(hooks, hook);
}

static inline void zai_hook_register_inheritor(zend_class_entry *child, zend_class_entry *parent, bool persistent) {
    const size_t min_size = 7;

    zend_ulong addr = ((zend_ulong)parent) << 3;
    zai_hook_inheritor_list *inheritors;
    zval *inheritors_zv;
    HashTable *ht = persistent ? &zai_hook_static_inheritors : &zai_hook_inheritors;
    if (!(inheritors_zv = zend_hash_index_find(ht, addr))) {
        inheritors = pemalloc(sizeof(zai_hook_inheritor_list) + sizeof(zend_class_entry *) * min_size, persistent);
        zend_hash_index_add_new_ptr(ht, addr, inheritors);
        inheritors->size = 1;
    } else {
        inheritors = Z_PTR_P(inheritors_zv);
        if (++inheritors->size > min_size && (inheritors->size & (inheritors->size - 1)) == 0) { // power of two test
            Z_PTR_P(inheritors_zv) = inheritors = perealloc(inheritors,
                                                            sizeof(zai_hook_inheritor_list) + (inheritors->size * 2 - 1) * sizeof(zend_class_entry *),
                                                            persistent);
        }
    }
    inheritors->inheritor[inheritors->size - 1] = child;
}

static inline void zai_hook_register_all_inheritors(zend_class_entry *ce, bool persistent) {
    if (ce->parent) {
        zai_hook_register_inheritor(ce, ce->parent, persistent);
    }
    for (uint32_t i = 0; i < ce->num_interfaces; ++i) {
        zai_hook_register_inheritor(ce, ce->interfaces[i], persistent);
    }
}

static inline void zai_hook_merge_inherited_hooks(zai_hooks_entry **hooks_entry, zend_function *function, zend_function *proto, zend_class_entry *ce) {
    zai_install_address addr = zai_hook_install_address(proto);
    zai_hooks_entry *protoHooks;
    if ((protoHooks = zend_hash_index_find_ptr(&zai_hook_resolved, addr))) {
        zai_hooks_entry *hooks = *hooks_entry;
        if (!hooks && !(hooks = zend_hash_index_find_ptr(&zai_hook_resolved, zai_hook_install_address(function)))) {
            *hooks_entry = hooks = zend_hash_index_add_ptr(&zai_hook_resolved, zai_hook_install_address(function), zai_hook_alloc_hooks_entry());
            zai_hook_resolve_hooks_entry(hooks, function);
#if PHP_VERSION_ID >= 80200
            // Internal functions duplicated onto userland classes share their run_time_cache with their parent function
            zai_hook_handle_internal_duplicate_function(hooks, ce, function);
#else
            (void)ce;
#endif
        }

        zval *hook_zv;
        zend_ulong index;
        ZEND_HASH_FOREACH_NUM_KEY_VAL(&protoHooks->hooks, index, hook_zv) {
            if ((hook_zv = zend_hash_index_add(&hooks->hooks, index, hook_zv))) {
                zai_hook_t *hook = Z_PTR_P(hook_zv);
                hooks->dynamic += hook->dynamic;
                Z_TYPE_INFO_P(hook_zv) = ZAI_IS_SHARED_HOOK_PTR;
                zai_hook_sort_newest(hooks);
            }
        } ZEND_HASH_FOREACH_END();
    }
}

static inline void zai_hook_resolve_lookup_inherited(zai_hooks_entry *hooks, zend_class_entry *ce, zend_function *function, zend_string *lcname) {
    // We are inheriting _something_. Let's check what exactly
    if (function->common.prototype || !ZEND_USER_CODE(function->type)) {
        // Note that abstract (incl interface) functions all have a dummy op_array with a ZEND_RETURN or are internal functions
        // thus their installed hooks can be looked up in resolved table
        for (uint32_t i = 0; i < ce->num_interfaces; ++i) {
            zend_function *proto;
            if ((proto = zend_hash_find_ptr(&ce->interfaces[i]->function_table, lcname))) {
                zai_hook_merge_inherited_hooks(&hooks, function, proto, ce);
            }
        }
        if (ce->parent) {
            zend_function *proto = zend_hash_find_ptr(&ce->parent->function_table, lcname);
            if (proto && ((proto->common.fn_flags & ZEND_ACC_ABSTRACT) || !ZEND_USER_CODE(function->type))) {
                zai_hook_merge_inherited_hooks(&hooks, function, proto, ce);
            }
        }
    }
}

static void zai_function_location_destroy(zval *zv) {
    efree(Z_PTR_P(zv));
}

static inline void zai_store_func_location(zend_function *func) {
    if (func->type != ZEND_USER_FUNCTION || !func->op_array.filename || (func->common.fn_flags & ZEND_ACC_CLOSURE)) {
        return;
    }

    const size_t min_size = 15;

    zval *entryzv;
    zai_function_location_entry *entry;
    if (!(entryzv = zend_hash_find(&zai_function_location_map, func->op_array.filename))) {
        entry = emalloc(sizeof(zai_function_location_entry) + min_size * sizeof(zend_function *));
        entry->size = 1;
        entry->ordered = 1;
        zend_hash_add_new_ptr(&zai_function_location_map, func->op_array.filename, entry);
    } else {
        entry = Z_PTR_P(entryzv);
        entry->ordered = 0;
        if (++entry->size > min_size && (entry->size & (entry->size - 1)) == 0) { // power of two test
            Z_PTR_P(entryzv) = entry = erealloc(entry, sizeof(zai_function_location_entry) + (entry->size * 2 - 1) * sizeof(zend_function *));
        }
    }

    entry->functions[entry->size - 1] = func;
}

static int zai_function_location_map_cmp(const void *a, const void *b) {
    return (int)(*(zend_op_array **)a)->line_start - (int)(*(zend_op_array **)b)->line_start;
}

zend_function *zai_hook_find_containing_function(zend_function *func) {
    if (func->type != ZEND_USER_FUNCTION || !func->op_array.filename) {
        return NULL;
    }

    zai_function_location_entry *entry;
    if (!(entry = zend_hash_find_ptr(&zai_function_location_map, func->op_array.filename))) {
        return NULL;
    }

    if (UNEXPECTED(entry->ordered == 0)) {
        qsort(entry->functions, entry->size, sizeof(zend_function *), zai_function_location_map_cmp);
        entry->ordered = 1;
    }

    // binary search for function with lower or equal start line than Closure
    uint32_t line = func->op_array.line_start;
    size_t low = 0, high = entry->size - 1;
    while (low < high) {
        size_t cur = low + ((high - low) >> 1) + ((high - low) & 1); // divide by two and ceil, ensures the last step is never higher than line

        int diff = (int)entry->functions[cur]->op_array.line_start - (int)line;
        if (diff == 0) {
            return entry->functions[cur];
        } else if (diff < 0) {
            low = cur;
        } else {
            high = cur - 1;
        }
    }

    if (entry->functions[low]->op_array.line_start > line || entry->functions[low]->op_array.line_end < line) {
        return NULL;
    }

    return entry->functions[low];
}

static inline void zai_hook_resolve(HashTable *base_ht, zend_class_entry *ce, zend_function *function, zend_string *lcname) {
    zai_hooks_entry *hooks;
    if ((hooks = zend_hash_find_ptr(base_ht, lcname))) {
        bool is_abstract = (function->common.fn_flags & ZEND_ACC_ABSTRACT) != 0;
        // We do not support tracing abstract trait methods for now.
        // At least symmetric support (i.e. supporting after renames for example) is hard.
        // Currently we consider the complexity not worth it.
        if (is_abstract && (ce->ce_flags & ZEND_ACC_TRAIT)) {
            zend_hash_del(base_ht, lcname);
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
                hook->is_abstract = true;
                existingHooks->dynamic += hook->dynamic;
                zend_hash_index_add_new(&existingHooks->hooks, index, hook_zv);
                zai_hook_sort_newest(existingHooks);
            } ZEND_HASH_FOREACH_END();

            // we remove the whole zai_hooks_entry, excluding the individual zai_hook_t which we moved
            hooks->hooks.pDestructor = NULL;
            zend_hash_del(base_ht, lcname);

            hooks = existingHooks;
        } else {
#if PHP_VERSION_ID >= 80200
            zai_hook_handle_internal_duplicate_function(hooks, ce, function);
#endif

            // we remove the function entry in the base table, but preserve the zai_hooks_entry
            base_ht->pDestructor = NULL;
            zend_hash_del(base_ht, lcname);
            base_ht->pDestructor = zai_hook_hash_destroy;
            zai_hook_resolve_hooks_entry(hooks, function);
            zai_hook_t *hook;
            ZEND_HASH_FOREACH_PTR(&hooks->hooks, hook) {
                hook->resolved_scope = ce;
                hook->is_abstract = is_abstract;
            } ZEND_HASH_FOREACH_END();
        }
    }

    if (function->common.scope == ce || !ZEND_USER_CODE(function->type)) {
        zai_hook_resolve_lookup_inherited(hooks, ce, function, lcname);
#if PHP_VERSION_ID >= 80000
        zai_hook_on_function_resolve(function);
#endif
    }
}

/* {{{ */
void zai_hook_resolve_function(zend_function *function, zend_string *lcname) {
    zai_hook_resolve(&zai_hook_request_functions, NULL, function, lcname);
    zai_store_func_location(function);
}

void zai_hook_resolve_class(zend_class_entry *ce, zend_string *lcname) {
    zend_function *function;

    zai_hook_register_all_inheritors(ce, false);

    zend_string *fnname;
    HashTable *method_table = zend_hash_find_ptr(&zai_hook_request_classes, lcname);
    if (!method_table) {
        ZEND_HASH_FOREACH_STR_KEY_PTR(&ce->function_table, fnname, function) {
            zai_store_func_location(function);
            if (function->common.scope == ce || !ZEND_USER_CODE(function->type)) {
                zai_hook_resolve_lookup_inherited(NULL, ce, function, fnname);
#if PHP_VERSION_ID >= 80000
                zai_hook_on_function_resolve(function);
#endif
            }
        } ZEND_HASH_FOREACH_END();
        return;
    }

    ZEND_HASH_FOREACH_STR_KEY_PTR(&ce->function_table, fnname, function) {
        zai_hook_resolve(method_table, ce, function, fnname);
        zai_store_func_location(function);
    } ZEND_HASH_FOREACH_END();

    if (zend_hash_num_elements(method_table) == 0) {
        // note: no pDestructor handling needed: zai_hook_resolve empties the table for us
        zend_hash_del(&zai_hook_request_classes, lcname);
    }
}

void zai_hook_resolve_file(zend_op_array *op_array) {
    zai_install_address addr = zai_hook_install_address_user(op_array);
    zend_hash_index_add_ptr(&zai_hook_resolved, addr, &zai_hook_request_files);
}

void zai_hook_unresolve_op_array(zend_op_array *op_array) {
    // May be called in shutdown_executor, which is after extension rshutdown
    if ((zend_long)zai_hook_id == -1) {
        return;
    }

    zai_install_address addr = zai_hook_install_address_user(op_array);
    if (op_array->function_name) {
        zai_hook_entries_remove_resolved(addr);
    } else {
        // skip freeing for file op_arrays, these are handled via zai_hook_request_files
        zend_hash_index_del(&zai_hook_resolved, addr);
    }
}

static inline void zai_hook_remove_shared_hook(zend_function *func, zend_ulong hook_id, zai_hooks_entry *base_hooks) {
    zai_install_address addr = zai_hook_install_address(func);
    zai_hooks_entry *hooks = zend_hash_index_find_ptr(&zai_hook_resolved, addr);
    // In case of inheritance of a userland method, the parent and child function will point to the same opcodes and thus hooks.
    // Ensure hooks are only removed once.
    if (hooks && hooks != base_hooks) {
        zend_hash_index_del(&hooks->hooks, hook_id);
        if (zend_hash_num_elements(&hooks->hooks) == 0) {
            zai_hook_entries_remove_resolved(addr);
        }
    }
}

static void zai_hook_remove_abstract_recursive(zai_hooks_entry *base_hooks, zend_class_entry *scope, zend_string *function_name, zend_ulong hook_id) {
    // find implementers by searching through all inheritors, recursively, stopping upon finding a non-abstract implementation
    zai_hook_inheritor_list *inheritors;
    zend_ulong ce_addr = ((zend_ulong)scope) << 3;
    if ((inheritors = zend_hash_index_find_ptr(&zai_hook_inheritors, ce_addr))) {
        for (size_t i = inheritors->size; i--;) {
            zend_class_entry *inheritor = inheritors->inheritor[i];
            zend_function *override = zend_hash_find_ptr(&inheritor->function_table, function_name);
            if (override) {
                zai_hook_remove_shared_hook(override, hook_id, base_hooks);
            }
            if (!override || (override->common.fn_flags & ZEND_ACC_ABSTRACT) != 0) {
                zai_hook_remove_abstract_recursive(base_hooks, inheritor, function_name, hook_id);
            }
        }
    }
}

static void zai_hook_remove_internal_inherited_recursive(zend_class_entry *scope, zend_string *function_name, zend_ulong hook_id, zif_handler handler) {
    // find implementers by searching through all inheritors, recursively, stopping upon finding an explicit override
    zai_hook_inheritor_list *inheritors;
    zend_ulong ce_addr = ((zend_ulong)scope) << 3;
    if ((inheritors = zend_hash_index_find_ptr(&zai_hook_inheritors, ce_addr))) {
        for (size_t i = inheritors->size; i--;) {
            zend_class_entry *inheritor = inheritors->inheritor[i];
            zend_function *child_function = zend_hash_find_ptr(&inheritor->function_table, function_name);
            if (child_function && !ZEND_USER_CODE(child_function->type) && child_function->internal_function.handler == handler) {
                zai_hook_remove_shared_hook(child_function, hook_id, NULL);
                zai_hook_remove_internal_inherited_recursive(inheritor, function_name, hook_id, handler);
            }
        }
    }
}

static bool zai_hook_remove_from_entry(zai_hooks_entry *hooks, zend_ulong index) {
    zai_hook_t *hook = zend_hash_index_find_ptr(&hooks->hooks, index);

    if (!hook || hook->id < 0) {
        return false;
    }

    hooks->dynamic -= hook->dynamic;
    if (!--hook->refcount) {
        // abstract and internal functions are never temporary, hence access to resolved is allowed here
        if (hook->is_abstract) {
            zai_hook_remove_abstract_recursive(hooks, hooks->resolved->common.scope, hook->function, index);
        } else if (hooks->resolved && !ZEND_USER_CODE(hooks->resolved->type) && hooks->resolved->common.scope) {
            zai_hook_remove_internal_inherited_recursive(hooks->resolved->common.scope, hook->function, index, hooks->resolved->internal_function.handler);
        }
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

    uint32_t allocated_hook_count = zend_hash_num_elements(&hooks->hooks);
    if (allocated_hook_count == 0) {
        return ZAI_HOOK_SKIP;
    }

    size_t hook_info_size = allocated_hook_count * sizeof(zai_hook_info);
    size_t dynamic_size = hooks->dynamic + hook_info_size;
    // a vector of first N hook_info entries, then N entries of variable size (as much memory as the individual hooks require)
    memory->dynamic = ecalloc(1, dynamic_size);
    memory->invocation = ++zai_hook_invocation;

    // iterate the array in a safe way, i.e. handling possible updates at runtime
    HashPosition pos;
    zend_hash_internal_pointer_reset_ex(&hooks->hooks, &pos);
    uint32_t ht_iter = zend_hash_iterator_add(&hooks->hooks, pos);
    uint32_t hook_num = 0;
    size_t dynamic_offset = hook_info_size;
    bool check_scope = ex->func->common.scope != NULL && ex->func->common.function_name != NULL;

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
            for (uint32_t i = 0; i < hook_num; ++i) {
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

            memory->hook_count = (zend_ulong)hook_num;
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

    memory->hook_count = (zend_ulong)hook_num;
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
            zend_ulong address = zai_hook_frame_address(ex);
            zai_hook_table_find(&zai_hook_resolved, address, (void**)&hooks);
            zval *hook_zv;
            if ((hook_zv = zend_hash_index_find(&hooks->hooks, (zend_ulong) -hook->id))) {
                if (Z_TYPE_INFO_P(hook_zv) == ZAI_IS_SHARED_HOOK_PTR) {
                    // lookup primary by name
                    zend_class_entry *ce = NULL;
                    zend_function *origin_func = zai_hook_lookup_function(ZAI_STRING_FROM_ZSTR(hook->scope), ZAI_STRING_FROM_ZSTR(hook->function), &ce);
                    zai_hook_table_find(&zai_hook_resolved, zai_hook_install_address(origin_func), (void**)&hooks);
                    zai_hook_remove_abstract_recursive(hooks, ce, hook->function, (zend_ulong)-hook->id);
                    address = zai_hook_install_address(hooks->resolved);
                }
                zend_hash_index_del(&hooks->hooks, (zend_ulong) -hook->id);
                if (zend_hash_num_elements(&hooks->hooks) == 0) {
                    zai_hook_entries_remove_resolved(address);
                }
            }
        }
    }

    efree(memory->dynamic);

    memory->dynamic = NULL;
} /* }}} */

/* {{{ */
bool zai_hook_minit(void) {
    zend_hash_init(&zai_hook_static_inheritors, 8, NULL, zai_hook_static_inheritors_destroy, 1);
    zend_hash_init(&zai_hook_static, 8, NULL, zai_hook_static_destroy, 1);
    zai_hook_static.nNextFreeElement = 1;
    return true;
}

bool zai_hook_rinit(void) {
    zend_hash_init(&zai_hook_inheritors, 8, NULL, zai_hook_inheritors_destroy, 0);
    zend_hash_init(&zai_hook_request_files.hooks, 8, NULL, zai_hook_destroy, 0);
    zend_hash_init(&zai_hook_request_functions, 8, NULL, zai_hook_hash_destroy, 0);
    zend_hash_init(&zai_hook_request_classes, 8, NULL, zai_hook_hash_destroy, 0);
    zend_hash_init(&zai_hook_resolved, 8, NULL, NULL, 0);
    zend_hash_init(&zai_function_location_map, 8, NULL, zai_function_location_destroy, 0);

    // reserve low hook ids for static hooks
    zai_hook_id = (zend_ulong)zai_hook_static.nNextFreeElement;

    zend_ulong index;
    zai_hook_inheritor_list *inheritors;
    ZEND_HASH_FOREACH_NUM_KEY_PTR(&zai_hook_static_inheritors, index, inheritors) {
        // round to nearest power of two minus 1
        size_t size = inheritors->size;
        if (size < 7) {
            size = 7;
        } else {
            size |= size >> 1;
            size |= size >> 2;
            size |= size >> 4;
            size |= size >> 8;
            size |= size >> 16;
            size |= size >> 32;
        }

        zai_hook_inheritor_list *copy = emalloc(sizeof(zai_hook_inheritor_list) + size * sizeof(zend_class_entry *));
        memcpy(copy, inheritors, sizeof(zai_hook_inheritor_list) + inheritors->size * sizeof(zend_class_entry *));
        zend_hash_index_add_new_ptr(&zai_hook_inheritors, index, copy);
    } ZEND_HASH_FOREACH_END();

    return true;
}

void zai_hook_post_startup(void) {
    zend_class_entry *ce;
    ZEND_HASH_FOREACH_PTR(CG(class_table), ce) {
#if PHP_VERSION_ID >= 70400
        // preloading check
        if (ce->ce_flags & ZEND_ACC_LINKED)
#endif
        {
            zai_hook_register_all_inheritors(ce, true);
        }
    } ZEND_HASH_FOREACH_END();
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

static int zai_hook_clean_graceful_del(zval *zv) {
    zai_hook_entries_destroy(Z_PTR_P(zv), ((Bucket *)zv)->h);
    return ZEND_HASH_APPLY_REMOVE;
}

void zai_hook_rshutdown(void) {
    zai_hook_id = -1;

    // freeing this after a bailout is a bad idea: at least resolved hooks will contain objects, which are invalid when destroyed here.
    if (!CG(unclean_shutdown)) {
        zend_hash_apply(&zai_hook_resolved, zai_hook_clean_graceful_del);
        zend_hash_destroy(&zai_hook_resolved);

        zend_hash_destroy(&zai_hook_inheritors);
        zend_hash_destroy(&zai_hook_request_functions);
        zend_hash_destroy(&zai_hook_request_classes);
        zend_hash_destroy(&zai_hook_request_files.hooks);
        zend_hash_destroy(&zai_function_location_map);
    }
}

void zai_hook_mshutdown(void) { zend_hash_destroy(&zai_hook_static); } /* }}} */

/* {{{ */
zend_long zai_hook_install_resolved_generator(zend_function *function,
        zai_hook_begin begin, zai_hook_generator_resume resumption, zai_hook_generator_yield yield, zai_hook_end end,
        zai_hook_aux aux, size_t dynamic) {
    if (!PG(modules_activated)) {
        /* not allowed: can only do resolved install during request */
        return -1;
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
        .is_abstract = (function->common.fn_flags & ZEND_ACC_ABSTRACT) != 0,
        .id = 0,
        .dynamic = dynamic,
        .refcount = 1,
    };

    return hook->id = zai_hook_resolved_install(hook, function, function->common.scope);
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
    bool persistent = !PG(modules_activated);

    zai_hook_t *hook = pemalloc(sizeof(*hook), persistent);
    *hook = (zai_hook_t){
        .scope = scope.len ? zai_zend_string_init_lower(scope.ptr, scope.len, persistent) : NULL,
        .function = function.len ? zai_zend_string_init_lower(function.ptr, function.len, persistent) : NULL,
        .resolved_scope = NULL,
        .begin = begin,
        .generator_resume = resumption,
        .generator_yield = yield,
        .end = end,
        .aux = aux,
        .is_global = false,
        .is_abstract = false,
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
            zai_hook_entries_remove_resolved(function_address);
        }

        return true;
    }
    return false;
}

bool zai_hook_remove(zai_string_view scope, zai_string_view function, zend_long index) {
    if (!function.len) {
        return zai_hook_remove_from_entry(&zai_hook_request_files, (zend_ulong)index);
    }

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
    // graceful clean: ensure that destructors executing during cleanup may still access zai_hook_resolved
    zend_hash_apply(&zai_hook_resolved, zai_hook_clean_graceful_del);
    zend_hash_clean(&zai_hook_request_functions);
    zend_hash_clean(&zai_hook_request_classes);

    zend_hash_iterators_remove(&zai_hook_request_files.hooks);
    zend_hash_clean(&zai_hook_request_files.hooks);
    zai_hook_request_files.dynamic = 0;

    zend_hash_clean(&zai_function_location_map);
}

static void zai_hook_iterator_set_current_and_advance(zai_hook_iterator *it) {
    HashPosition pos = zend_hash_iterator_pos(it->iterator.iter, it->iterator.ht);
    zai_hook_t *hook;
    zval *hook_zv;
    while ((hook_zv = zend_hash_get_current_data_ex(it->iterator.ht, &pos))
        && (Z_TYPE_INFO_P(hook_zv) == ZAI_IS_SHARED_HOOK_PTR || (hook = Z_PTR_P(hook_zv))->id < 0)) {
        zend_hash_move_forward_ex(it->iterator.ht, &pos);
    }
    if (hook_zv) {
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

#define zai_hook_installed_operation(default, callback, resolved_callback) \
    zend_class_entry *ce = NULL; \
    zend_function *resolved = zai_hook_lookup_function(scope, function, &ce); \
    if (resolved) { \
        /* Abstract traits are not supported and shall not return methods from trait useing classes */ \
        if ((resolved->common.fn_flags & ZEND_ACC_ABSTRACT) && (ce->ce_flags & ZEND_ACC_TRAIT)) { \
            return default; \
        } \
        return resolved_callback(resolved); \
    } \
    \
    HashTable *base_ht; \
    if (scope.len) { \
        base_ht = zend_hash_str_find_ptr(&zai_hook_request_classes, scope.ptr, scope.len); \
        if (!base_ht) { \
            return default; \
        } \
    } else { \
        base_ht = &zai_hook_request_functions; \
    } \
    HashTable *hooks = zend_hash_str_find_ptr(base_ht, function.ptr, function.len); \
    if (!hooks) { \
        return default; \
    } \
    return callback(hooks); \


zai_hook_iterator zai_hook_iterate_installed(zai_string_view scope, zai_string_view function) {
    zai_hook_installed_operation((zai_hook_iterator){0}, zai_hook_iterator_init, zai_hook_iterate_resolved)
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

uint32_t zai_hook_count_installed(zai_string_view scope, zai_string_view function) {
    zai_hook_installed_operation(0, zend_hash_num_elements, zai_hook_count_resolved)
}

uint32_t zai_hook_count_resolved(zend_function *function) {
    HashTable *hooks = zend_hash_index_find_ptr(&zai_hook_resolved, zai_hook_install_address(function));
    if (!hooks) {
        return 0;
    }
    return zend_hash_num_elements(hooks);
}

