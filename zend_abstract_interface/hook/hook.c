#include <hook/hook.h>
#include <hook/string.h>
#include <hook/table.h>
#include <value/value.h>

// clang-format off

/* {{{ */
typedef struct {
    zai_hook_type_t    type;
    zai_hook_string_t* scope;
    zai_hook_string_t* function;
    zai_hook_begin     begin;
    zai_hook_end       end;
    void*              fixed;
    size_t             dynamic;
    size_t             offset;
} zai_hook_t; /* }}} */

// clang-format on

/* {{{ private reserved counter */
__thread size_t zai_hook_reserved; /* }}} */

/* {{{ private tables */
static HashTable zai_hook_static;

__thread HashTable zai_hook_request;

__thread HashTable zai_hook_resolved; /* }}} */

/* {{{ some inlines need private tables above */
#include <hook/util.h> /* }}} */

/* {{{ */
static inline void zai_hook_copy(zai_hook_t *hook ZAI_TSRMLS_DC) {
    if (hook->type == ZAI_HOOK_USER) {
        zai_hook_copy_u(hook ZAI_TSRMLS_CC);
    }

    if (hook->scope) {
        zai_hook_string_copy(hook->scope);
    }

    zai_hook_string_copy(hook->function);
} /* }}} */

#if PHP_VERSION_ID < 70000
static void zai_hook_destroy(zai_hook_t *hook) {
#else
static void zai_hook_destroy(zval *zv) {
    zai_hook_t *hook = Z_PTR_P(zv);
#endif

    if (hook->type == ZAI_HOOK_USER) {
        zai_hook_destroy_u(hook ZAI_TSRMLS_CC);
    }

    if (hook->scope) {
        zai_hook_string_release(hook->scope);
    }

    zai_hook_string_release(hook->function);

#if PHP_VERSION_ID >= 70000
    pefree(hook, 1);
#endif
}

#if PHP_VERSION_ID < 70000
static void zai_hook_resolved_destroy(HashTable *hooks) {
#else
static void zai_hook_resolved_destroy(zval *zv) {
    HashTable *hooks = Z_PTR_P(zv);
#endif

    zend_hash_destroy(hooks);

#if PHP_VERSION_ID >= 70000
    pefree(hooks, 1);
#endif
}

/* {{{ */
#if PHP_VERSION_ID < 70000
static int zai_hook_resolve_impl(zai_hook_t *hook ZAI_TSRMLS_DC) {
#else
static int zai_hook_resolve_impl(zval *zv ZAI_TSRMLS_DC) {
    zai_hook_t *hook = Z_PTR_P(zv);
#endif
    zend_function *function = NULL;

    // clang-format off
    if (hook->scope) {
        zend_class_entry *scope =
            zai_symbol_lookup_class(
                ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
                zai_hook_string_cast(hook->scope) ZAI_TSRMLS_CC);

        if (!scope) {
            /* class not available */
            return ZEND_HASH_APPLY_KEEP;
        }

        function =
            zai_symbol_lookup_function(
                ZAI_SYMBOL_SCOPE_CLASS, scope,
                zai_hook_string_cast(hook->function) ZAI_TSRMLS_CC);
    } else {
        function =
            zai_symbol_lookup_function(
                ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
                zai_hook_string_cast(hook->function) ZAI_TSRMLS_CC);
    }
    // clang-format on

    if (!function) {
        /* cannot be resolved */
        return ZEND_HASH_APPLY_KEEP;
    }

    HashTable *table;
    zend_ulong address = zai_hook_install_address(function);

    if (!zai_hook_table_find(&zai_hook_resolved, address, &table)) {
        HashTable resolving;

        zend_hash_init(&resolving, 8, NULL, (dtor_func_t)zai_hook_destroy, 1);

        // clang-format off
        if (!zai_hook_table_insert_at(
                &zai_hook_resolved, address,
                &resolving, sizeof(HashTable), (void **)&table)) {
            zend_hash_destroy(&resolving);

            return ZEND_HASH_APPLY_KEEP;
        }
        // clang-format on
    }

    zai_hook_t *resolved;

    if (!zai_hook_table_insert(table, hook, sizeof(zai_hook_t), (void **)&resolved)) {
        return ZEND_HASH_APPLY_KEEP;
    }

    resolved->offset = zai_hook_reserved;

    zai_hook_reserved += resolved->dynamic;

    zai_hook_copy(resolved ZAI_TSRMLS_CC);

    return ZEND_HASH_APPLY_REMOVE;
}

/* {{{ */
void zai_hook_resolve(ZAI_TSRMLS_D) {
    if (zend_hash_num_elements(&zai_hook_request) == 0) {
        return;
    }

    zend_hash_apply(&zai_hook_request, (apply_func_t)zai_hook_resolve_impl ZAI_TSRMLS_CC);
} /* }}} */

/* {{{ */
static inline HashTable *zai_hook_find(zend_execute_data *ex) {
    HashTable *hooks;

    if (!zai_hook_table_find(&zai_hook_resolved, zai_hook_frame_address(ex), &hooks)) {
        return NULL;
    }

    return hooks;
} /* }}} */

/* {{{ */
bool zai_hook_installed(zend_execute_data *ex ZAI_TSRMLS_CC) {
    return zend_hash_index_exists(&zai_hook_resolved, zai_hook_frame_address(ex));
}
/* }}} */

/* {{{ */
bool zai_hook_continue(zend_execute_data *ex, void **reserved ZAI_TSRMLS_DC) {
    HashTable *hooks = zai_hook_find(ex);

    if (!hooks || zend_hash_num_elements(hooks) == 0) {
        return true;
    }

    if (zai_hook_reserved) {
        *reserved = pemalloc(zai_hook_reserved, 1);

        memset(*reserved, 0, zai_hook_reserved);
    }

    zval *This = zai_hook_this(ex);

    zai_hook_t *hook;
    ZAI_HOOK_FOREACH(hooks, hook, {
        switch (hook->type) {
            case ZAI_HOOK_INTERNAL:
                if (!hook->begin.i) {
                    continue;
                }

                void *dynamic = NULL;

                if (hook->dynamic) {
                    dynamic = (((char *)*reserved) + hook->offset);
                }

                if (!hook->begin.i(ex, hook->fixed, dynamic ZAI_TSRMLS_CC)) {
                    goto __zai_hook_finish;
                }
                break;

            case ZAI_HOOK_USER: {
                if (Z_TYPE(hook->begin.u) != IS_OBJECT) {
                    continue;
                }

                // clang-format off
                zval *rvu;
                ZAI_VALUE_INIT(rvu);
                zai_symbol_call(
                    This ?
                        ZAI_SYMBOL_SCOPE_OBJECT : ZAI_SYMBOL_SCOPE_GLOBAL,
                    This ?
                        This : NULL,
                    ZAI_SYMBOL_FUNCTION_CLOSURE, &hook->begin.u,
                    &rvu ZAI_TSRMLS_CC, 0);
                // clang-format on
                bool stop = zai_hook_returned_false(rvu);
                ZAI_VALUE_DTOR(rvu);

                if (stop) {
                    goto __zai_hook_finish;
                }
            } break;
        }
    });

    return true;

__zai_hook_finish:
    zai_hook_finish(ex, NULL, reserved ZAI_TSRMLS_CC);
    return false;
} /* }}} */

/* {{{ */
void zai_hook_finish(zend_execute_data *ex, zval *rv, void **reserved ZAI_TSRMLS_DC) {
    HashTable *hooks = zai_hook_find(ex);

    if (!hooks || zend_hash_num_elements(hooks) == 0) {
        return;
    }

    zval *This = zai_hook_this(ex);

    zai_hook_t *hook;
    ZAI_HOOK_FOREACH(hooks, hook, {
        switch (hook->type) {
            case ZAI_HOOK_INTERNAL:
                if (!hook->end.i) {
                    continue;
                }

                void *dynamic = NULL;

                if (hook->dynamic) {
                    dynamic = (((char *)*reserved) + hook->offset);
                }

                hook->end.i(ex, rv, hook->fixed, dynamic ZAI_TSRMLS_CC);
                break;

            case ZAI_HOOK_USER: {
                if (Z_TYPE(hook->end.u) != IS_OBJECT) {
                    continue;
                }

                zval *rvu;
                // clang-format off
                ZAI_VALUE_INIT(rvu);
                zai_symbol_call(
                    This ?
                        ZAI_SYMBOL_SCOPE_OBJECT : ZAI_SYMBOL_SCOPE_GLOBAL,
                    This ?
                        This : NULL,
                    ZAI_SYMBOL_FUNCTION_CLOSURE, &hook->end.u,
                    &rvu ZAI_TSRMLS_CC,
                        rv != NULL ?
                            1 : 0,
                        rv != NULL ?
                            &rv : NULL);
                // clang-format on
                ZAI_VALUE_DTOR(rvu);
            } break;
        }
    });

    if (*reserved) {
        pefree(*reserved, 1);
    }
} /* }}} */

/* {{{ */
bool zai_hook_minit(void) {
    zend_hash_init(&zai_hook_static, 8, NULL, (dtor_func_t)zai_hook_destroy, 1);
    return true;
}

bool zai_hook_rinit(void) {
    zai_hook_reserved = 0;

    zend_hash_init(&zai_hook_request, 8, NULL, (dtor_func_t)zai_hook_destroy, 1);

    zai_hook_t *hook;
    ZAI_HOOK_FOREACH(&zai_hook_static, hook, {
        zai_hook_t *inherited;

        if (!zai_hook_table_insert(&zai_hook_request, hook, sizeof(zai_hook_t), (void **)&inherited)) {
            continue;
        }

        zai_hook_copy(inherited);
    });

    zend_hash_init(&zai_hook_resolved, 8, NULL, (dtor_func_t)zai_hook_resolved_destroy, 1);

    zai_hook_resolve(ZAI_TSRMLS_C);

    return true;
}

void zai_hook_rshutdown(void) {
    zend_hash_destroy(&zai_hook_resolved);
    zend_hash_destroy(&zai_hook_request);
}

void zai_hook_mshutdown(void) { zend_hash_destroy(&zai_hook_static); } /* }}} */

//clang-format off

/* {{{ */
bool zai_hook_install(
        zai_hook_type_t type,
        zai_string_view scope,
        zai_string_view function,
        zai_hook_begin begin,
        zai_hook_end end,
        void *fixed, size_t dynamic ZAI_TSRMLS_DC) {
    if (type == ZAI_HOOK_USER) {
        if (!PG(modules_activated)) {
            /* not allowed */
            return false;
        }

        if (fixed || dynamic) {
            /* not allowed */
            return false;
        }
    }

    if (!function.len) {
        /* not allowed */
        return false;
    }

    zai_hook_t install = {
        .type     = type,
        .scope    = zai_hook_string_from(&scope),
        .function = zai_hook_string_from(&function),
        .begin    = begin,
        .end      = end,
        .fixed    = fixed,
        .dynamic  = dynamic,
        .offset   = 0,
    };

    HashTable *table = zai_hook_install_table(ZAI_TSRMLS_C);

    zai_hook_t *hook;

    if (!zai_hook_table_insert(table, &install, sizeof(zai_hook_t), (void **)&hook)) {
        if (install.scope) {
            zai_hook_string_release(install.scope);
        }

        zai_hook_string_release(install.function);
        return false;
    }

    if (type == ZAI_HOOK_USER) {
        zai_hook_copy_u(hook);
    }

    return true;
} /* }}} */

// clang-format on
