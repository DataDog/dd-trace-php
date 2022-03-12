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
} zai_hook_t; /* }}} */

// clang-format on

/* {{{ private reserved counters */
__thread size_t zai_hook_dynamic_size; /* }}} */

/* {{{ private tables */
static HashTable zai_hook_static;

__thread HashTable zai_hook_request;

__thread HashTable zai_hook_resolved; /* }}} */

static void zai_hook_destroy(zval *zv);

#if PHP_VERSION_ID >= 80000
static void zai_hook_on_update_empty(zend_op_array *op_array, bool remove) { (void)op_array, (void)remove; }
void (*zai_hook_on_update)(zend_op_array *op_array, bool remove) = zai_hook_on_update_empty;
#endif

/* {{{ some inlines need private access */
#include <hook/memory.h> /* }}} */

/* {{{ */
static inline HashTable *zai_hook_install_table(void) {
    if (PG(modules_activated)) {
        return &zai_hook_request;
    }
    return &zai_hook_static;
} /* }}} */

/* {{{ */
static inline bool zai_hook_resolved_table(zend_ulong address, HashTable **resolved) {
    if (!zai_hook_table_find(&zai_hook_resolved, address, (void**)resolved)) {
        HashTable resolving;

        zend_hash_init(&resolving, 8, NULL, (dtor_func_t)zai_hook_destroy, 1);

        // clang-format off
        if (!zai_hook_table_insert_at(
                &zai_hook_resolved, address,
                &resolving, sizeof(HashTable), (void **)resolved)) {
            zend_hash_destroy(&resolving);

            return false;
        }
        // clang-format on
        return true;
    }

    return true;
} /* }}} */

/* {{{ */
static inline void zai_hook_copy(zai_hook_t *hook) {
    if (!hook->is_global) {
        if (hook->scope) {
            GC_ADDREF(hook->scope);
        }

        if (hook->function) {
            GC_ADDREF(hook->function);
        }
    }
} /* }}} */

static void zai_hook_destroy(zval *zv) {
    zai_hook_t *hook = Z_PTR_P(zv);

    if (!hook->is_global) {
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

    pefree(hook, 1);
}

static void zai_hook_resolved_destroy(zval *zv) {
    HashTable *hooks = Z_PTR_P(zv);

    zend_hash_destroy(hooks);

    pefree(hooks, 1);
}

/* {{{ */
static bool zai_hook_resolve_hook(zai_hook_t *hook) {
    zend_function *function = NULL;

    zai_string_view func_name = ZAI_STRING_FROM_ZSTR(hook->function);
    if (hook->scope) {
        zai_string_view class_name = ZAI_STRING_FROM_ZSTR(hook->scope);
        zend_class_entry *scope = zai_symbol_lookup_class(ZAI_SYMBOL_SCOPE_GLOBAL, NULL,&class_name);

        if (!scope) {
            /* class not available */
            return false;
        }
        function = zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_CLASS, scope, &func_name);
    } else {
        function = zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &func_name);
    }

    if (!function) {
        /* cannot be resolved */
        return false;
    }

    HashTable *table;

    if (!zai_hook_resolved_table(zai_hook_install_address(function), &table)) {
        return false;
    }

    zai_hook_t *resolved;

    if (!zai_hook_table_insert(table, hook, sizeof(zai_hook_t), (void **)&resolved)) {
        return false;
    }

    zai_hook_memory_reserve(resolved);

#if PHP_VERSION_ID >= 80000
    if (function->type == ZEND_USER_FUNCTION) {
        zai_hook_on_update(&function->op_array, false);
    }
#endif

    return true;
}

static int zai_hook_resolve_impl(zval *zv) {
    zai_hook_t *hook = Z_PTR_P(zv);
    if (zai_hook_resolve_hook(hook)) {
        zai_hook_copy(hook);
        return ZEND_HASH_APPLY_REMOVE;
    }
    return ZEND_HASH_APPLY_KEEP;
}
/* {{{ */
void zai_hook_resolve(void) {
    if (zend_hash_num_elements(&zai_hook_request) == 0) {
        return;
    }

    zend_hash_apply(&zai_hook_request, (apply_func_t)zai_hook_resolve_impl);
} /* }}} */

// TODO: make these two functions below efficient
void zai_hook_resolve_user_function(zend_op_array *op_array) {
    (void)op_array;
    zai_hook_resolve();
#if PHP_VERSION_ID > 80000
    // We do negative caching for run-time allocated op_arrays
    if (op_array->fn_flags & ZEND_ACC_HEAP_RT_CACHE) {
        zend_hash_index_add_ptr(&zai_hook_resolved, (zend_ulong)op_array->opcodes, NULL);
    }
#endif
}
void zai_hook_resolve_class(zend_class_entry *ce) {
    (void)ce;
    zai_hook_resolve();
}

/* {{{ */
static inline HashTable *zai_hook_find(zend_execute_data *ex) {
    HashTable *hooks;

    if (!zai_hook_table_find(&zai_hook_resolved, zai_hook_frame_address(ex), (void**)&hooks)) {
        return NULL;
    }

    return hooks;
} /* }}} */

/* {{{ */
bool zai_hook_continue(zend_execute_data *ex, zai_hook_memory_t *memory) {
    HashTable *hooks = zai_hook_find(ex);

    if (!hooks || zend_hash_num_elements(hooks) == 0) {
        return true;
    }

    zai_hook_memory_allocate(memory);

    zai_hook_t *hook;
    // clang-format off
    ZEND_HASH_FOREACH_PTR(hooks, hook) {
        if (!hook->begin) {
            continue;
        }

        if (!hook->begin(
                ex,
                zai_hook_memory_auxiliary(memory, hook),
                zai_hook_memory_dynamic(memory, hook))) {
            goto __zai_hook_finish;
        }
    } ZEND_HASH_FOREACH_END();
    // clang-format on

    return true;

__zai_hook_finish:
    zai_hook_finish(ex, NULL, memory);
    return false;
} /* }}} */

/* {{{ */
void zai_hook_finish(zend_execute_data *ex, zval *rv, zai_hook_memory_t *memory) {
    HashTable *hooks = zai_hook_find(ex);

    if (!hooks || zend_hash_num_elements(hooks) == 0) {
        return;
    }

    zai_hook_t *hook;
    // clang-format off
    ZEND_HASH_FOREACH_PTR(hooks, hook) {
        if (!hook->end) {
            continue;
        }

        hook->end(
            ex, rv,
            zai_hook_memory_auxiliary(memory, hook),
            zai_hook_memory_dynamic(memory, hook));
    } ZEND_HASH_FOREACH_END();
    // clang-format on

    zai_hook_memory_free(memory);
} /* }}} */

/* {{{ */
bool zai_hook_minit(void) {
    zend_hash_init(&zai_hook_static, 8, NULL, (dtor_func_t)zai_hook_destroy, 1);
    return true;
}

bool zai_hook_rinit(void) {
    zend_hash_init(&zai_hook_request, 8, NULL, (dtor_func_t) zai_hook_destroy, 1);
    zend_hash_init(&zai_hook_resolved, 8, NULL, (dtor_func_t)zai_hook_resolved_destroy, 1);

    return true;
}

void zai_hook_activate(void) {
    zai_hook_dynamic_size = 0;

    zai_hook_t *hook;
    ZEND_HASH_FOREACH_PTR(&zai_hook_static, hook) {
        zai_hook_t *inherited;

        if (!zai_hook_table_insert(&zai_hook_request, hook, sizeof(zai_hook_t), (void **)&inherited)) {
            continue;
        }

        inherited->is_global = true;
        zai_hook_copy(inherited);
    } ZEND_HASH_FOREACH_END();

    zai_hook_resolve();
}

void zai_hook_clean(void) {
    zend_hash_clean(&zai_hook_resolved);
    zend_hash_clean(&zai_hook_request);
}

void zai_hook_rshutdown(void) {
    zend_hash_destroy(&zai_hook_resolved);
    zend_hash_destroy(&zai_hook_request);
}

void zai_hook_mshutdown(void) { zend_hash_destroy(&zai_hook_static); } /* }}} */

// clang-format off

/* {{{ */
bool zai_hook_install_resolved(
        zai_hook_begin begin,
        zai_hook_end end,
        zai_hook_aux aux,
        size_t dynamic,
        zend_function *function) {
    if (!PG(modules_activated)) {
        /* not allowed: can only do resolved install during request */
        return false;
    }

    zai_hook_t install = {
        .scope = NULL,
        .function = NULL,
        .begin = begin,
        .end = end,
        .aux = aux,
        .dynamic = dynamic,
        .dynamic_offset = 0,
    };

    HashTable *table;

    if (!zai_hook_resolved_table(zai_hook_install_address(function), &table)) {
        return false;
    }

    zai_hook_t *resolved;

    if (!zai_hook_table_insert(table, &install, sizeof(zai_hook_t), (void **)&resolved)) {
        return false;
    }

    zai_hook_memory_reserve(resolved);


#if PHP_VERSION_ID >= 80000
    if (function->type == ZEND_USER_FUNCTION) {
        zai_hook_on_update(&function->op_array, false);
    }
#endif

    return true;
} /* }}} */

/* {{{ */
bool zai_hook_install(
        zai_string_view scope,
        zai_string_view function,
        zai_hook_begin begin,
        zai_hook_end end,
        zai_hook_aux aux,
        size_t dynamic) {

    if (!function.len) {
        /* not allowed: target must be known */
        return false;
    }

    bool persistent = !PG(modules_activated);

    zai_hook_t install = {
        .scope = scope.len ? zend_string_init(scope.ptr, scope.len, persistent) : NULL,
        .function = zend_string_init(function.ptr, function.len, persistent),
        .begin = begin,
        .end = end,
        .aux = aux,
        .dynamic = dynamic,
        .dynamic_offset = 0,
    };

    HashTable *table = zai_hook_install_table();

    zai_hook_t *hook;

    bool isUninitialized = table != &zai_hook_request;
    if ((isUninitialized || !zai_hook_resolve_hook(&install)) && !zai_hook_table_insert(table, &install, sizeof(zai_hook_t), (void **)&hook)) {
        if (install.scope) {
            zend_string_release(install.scope);
        }

        zend_string_release(install.function);
        return false;
    }

    return true;
} /* }}} */

void zai_hook_remove(zai_string_view scope, zai_string_view function, int index) {
    // TODO implement
}

// clang-format on
