#include <sandbox/sandbox.h>
#include "symbols.h"
#include "zend_compile.h"

// clang-format off
static inline bool zai_symbol_update(zend_class_entry *ce ZAI_TSRMLS_DC) {
    if (ce->ce_flags & ZEND_ACC_CONSTANTS_UPDATED) {
        return true;
    }

    volatile bool zai_symbol_updated = true;

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    zend_try {
#if PHP_VERSION_ID < 70000
        zend_update_class_constants(ce ZAI_TSRMLS_CC);
#else
        if (zend_update_class_constants(ce) != SUCCESS) {
            zai_symbol_updated = false;
        }
#endif
    } zend_catch {
        zai_symbol_updated = false;
    } zend_end_try();

    if (zai_symbol_updated && !EG(exception)) {
        zai_sandbox_close(&sandbox);
        return true;
    }

    zai_sandbox_close(&sandbox);
    return false;
}

static inline void *zai_symbol_lookup_table(HashTable *table, zai_string_view key, bool ncase, bool pointer) {
#if PHP_VERSION_ID >= 70000
    zval *result = zend_hash_str_find(table, key.ptr, key.len);
#else
    void *result = NULL;

    zend_hash_find(table, key.ptr, key.len + 1, (void **)&result);
#endif

    if (!result && ncase) {
        char *ptr = (char *)pemalloc(key.len + 1, 1);

        for (uint32_t c = 0; c < key.len; c++) {
            ptr[c] = tolower(key.ptr[c]);
        }

        ptr[key.len] = 0;

#if PHP_VERSION_ID >= 70000
        result = zend_hash_str_find(table, ptr, key.len);
#else
        zend_hash_find(table, ptr, key.len + 1, (void **)&result);
#endif

        pefree(ptr, 1);
    }

#if PHP_VERSION_ID >= 70000
    if (pointer && result) {
        return Z_PTR_P(result);
    } else {
        return result;
    }
#else
    (void) pointer;
#endif
    return result;
}

static inline zai_string_view zai_symbol_lookup_clean(zai_string_view view) {
    if (!view.len || *view.ptr != '\\') {
        return view;
    }
    return (zai_string_view){ .ptr = view.ptr + 1, .len = view.len - 1 };
}

static inline zai_string_view zai_symbol_lookup_key(zai_string_view *namespace, zai_string_view *name, bool lower) {
    zai_string_view rv;
    zai_string_view vns     = zai_symbol_lookup_clean(*namespace);
    zai_string_view vn      = zai_symbol_lookup_clean(*name);

    char *result = NULL;
#define CHAR_AT(n) (result[n])
    if (vns.len) {
        rv.len = vns.len + vn.len + 1;
        result = pemalloc(rv.len + 1, 1);

        memcpy(&CHAR_AT(0), vns.ptr, vns.len);

        for (uint32_t c = 0; c < vns.len; c++) {
            CHAR_AT(c) = tolower(CHAR_AT(c));
        }

        CHAR_AT(vns.len) = '\\';
        memcpy(&CHAR_AT(vns.len + 1), vn.ptr, vn.len);
    } else {
        rv.len = vn.len;
        result = pemalloc(rv.len + 1, 1);

        memcpy(&CHAR_AT(0), vn.ptr, vn.len);
    }

    if (lower) {
        for (uint32_t c = vns.len; c < rv.len; c++) {
            CHAR_AT(c) = tolower(CHAR_AT(c));
        }
    }

    CHAR_AT(rv.len) = 0;
#undef CHAR_AT
    rv.ptr = result;

    return rv;
}

static inline zval* zai_symbol_lookup_return_zval(zval *zv) {
    if (!zv) {
        return NULL;
    }

#if PHP_VERSION_ID < 70000
    return *(zval**) zv;
#else
    while (Z_TYPE_P(zv) == IS_INDIRECT) {
        zv = Z_INDIRECT_P(zv);
    }

    ZVAL_DEREF(zv);

    return zv;
#endif
}

static inline zend_class_entry *zai_symbol_lookup_class_impl(zai_symbol_scope_t scope_type, void *scope, zai_string_view *name ZAI_TSRMLS_DC) {
    void *result = NULL;

    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_GLOBAL: {
            result = zai_symbol_lookup_table(
                EG(class_table),
                zai_symbol_lookup_clean(*name),
                true, true);
        } break;

        case ZAI_SYMBOL_SCOPE_NAMESPACE: {
            zai_string_view key = zai_symbol_lookup_key(scope, name, true);

            if (key.ptr) {
                result = zai_symbol_lookup_table(
                    EG(class_table),
                    key,
                    false, true);

                pefree((char*) key.ptr, 1);
            }
        } break;

        default:
            assert(0 && "class lookup may only be performed in global and namespace scopes");
    }

#if PHP_VERSION_ID >= 70000
    return result;
#else
    return result ? *(void **)result : NULL;
#endif
}

static inline zend_function *zai_symbol_lookup_function_impl(zai_symbol_scope_t scope_type, void *scope, zai_string_view *name ZAI_TSRMLS_DC) {
    HashTable *table = NULL;
    zai_string_view key = *name;

    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_CLASS:
            table = &((zend_class_entry *)scope)->function_table;
            break;

        case ZAI_SYMBOL_SCOPE_OBJECT:
            table = &Z_OBJCE_P((zval *)scope)->function_table;
            break;

        case ZAI_SYMBOL_SCOPE_NAMESPACE:
            key = zai_symbol_lookup_key(scope, name, true);
            /* intentional fall through */

        case ZAI_SYMBOL_SCOPE_GLOBAL:
            table = EG(function_table);
            break;

        default:
            assert(0 && "function lookup may not be performed in static and frame scopes");
            return NULL;
    }

    zend_function *function = zai_symbol_lookup_table(
        table,
        key.ptr == name->ptr ? zai_symbol_lookup_clean(key) : key,
        scope_type != ZAI_SYMBOL_SCOPE_NAMESPACE, true);

    if (key.ptr != name->ptr) {
        pefree((char*) key.ptr, 1);
    }

    return function;
}

static inline zval* zai_symbol_lookup_constant_global(zai_symbol_scope_t scope_type, void *scope, zai_string_view *name ZAI_TSRMLS_DC) {
    zai_string_view key = *name;

    if (scope_type == ZAI_SYMBOL_SCOPE_NAMESPACE) {
        key = zai_symbol_lookup_key(scope, name, false);
    }

    zend_constant *constant = zai_symbol_lookup_table(
        EG(zend_constants),
        key.ptr == name->ptr ? zai_symbol_lookup_clean(key) : key,
        false, true);

    if (key.ptr != name->ptr) {
        pefree((char*) key.ptr, 1);
    }

    if (!constant) {
        return NULL;
    }

    return &constant->value;
}

static inline zval* zai_symbol_lookup_constant_class(zai_symbol_scope_t scope_type, void *scope, zai_string_view *name ZAI_TSRMLS_DC) {
    zend_class_entry *ce;

    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_CLASS:
            ce = (zend_class_entry*) scope;
        break;

        case ZAI_SYMBOL_SCOPE_OBJECT:
            ce = Z_OBJCE_P((zval*) scope);
        break;

        default: { /* unreachable */ }
    }

    if (!zai_symbol_update(ce ZAI_TSRMLS_CC)) {
        return NULL;
    }

#if PHP_VERSION_ID >= 70100
    zend_class_constant *constant = zai_symbol_lookup_table(&ce->constants_table, *name, false, true);

    if (!constant) {
        return NULL;
    }

    return &constant->value;
#elif PHP_VERSION_ID >= 70000
    zval *constant = zai_symbol_lookup_table(&ce->constants_table, *name, false, false);

    if (!constant) {
        return NULL;
    }

    return constant;
#else
    zval **constant = zai_symbol_lookup_table(&ce->constants_table, *name, false, false);

    if (!constant) {
        return NULL;
    }

    return *constant;
#endif
}

static inline zval* zai_symbol_lookup_constant_impl(
        zai_symbol_scope_t scope_type, void *scope,
        zai_string_view *name ZAI_TSRMLS_DC) {
    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_GLOBAL:
        case ZAI_SYMBOL_SCOPE_NAMESPACE:
            return zai_symbol_lookup_constant_global(scope_type, scope, name ZAI_TSRMLS_CC);

        case ZAI_SYMBOL_SCOPE_CLASS:
        case ZAI_SYMBOL_SCOPE_OBJECT:
            return zai_symbol_lookup_constant_class(scope_type, scope, name ZAI_TSRMLS_CC);

        default:
            assert(0 && "constant lookup may not be performed in static and frame scopes");
            return NULL;
    }
}

static inline zval* zai_symbol_lookup_property_impl(
        zai_symbol_scope_t scope_type, void *scope,
        zai_string_view *name ZAI_TSRMLS_DC) {
    zend_property_info *info = NULL;
    zend_class_entry *ce = NULL;

    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_OBJECT:
            ce = Z_OBJCE_P((zval*) scope);
        break;

        case ZAI_SYMBOL_SCOPE_CLASS:
            ce = (zend_class_entry*) scope;
        break;

        default:
            assert(0 && "property lookup may only be performed in class and object scopes");
            return NULL;
    }

    info = zai_symbol_lookup_table(&ce->properties_info, *name, false, true);

    if (!info) {
        if (scope_type == ZAI_SYMBOL_SCOPE_OBJECT) {
#if PHP_VERSION_ID < 70000
            zend_object *obj = zend_object_store_get_object((zval*) scope ZAI_TSRMLS_CC);
#else
            zend_object *obj = Z_OBJ_P((zval*) scope);
#endif
            zval *property = (zval*)
                zai_symbol_lookup_table(
                    obj->properties,
                    *name,
                    false, false);

            return zai_symbol_lookup_return_zval(property);
        }
        return NULL;
    }

    if (scope_type == ZAI_SYMBOL_SCOPE_CLASS) {
        if ( !(info->flags & ZEND_ACC_STATIC)) {
            return NULL;
        }

        if (!zai_symbol_update(ce ZAI_TSRMLS_CC)) {
            return NULL;
        }

#if PHP_VERSION_ID < 70000
        if (!CE_STATIC_MEMBERS(ce)) {
            return NULL;
        }

        return CE_STATIC_MEMBERS(ce)[info->offset];
#else

#if PHP_VERSION_ID >= 70300
        if (CE_STATIC_MEMBERS(ce) == NULL) {
            zend_class_init_statics(ce);
        }
#endif
        return zai_symbol_lookup_return_zval(CE_STATIC_MEMBERS(ce) + info->offset);
#endif
    }

#if PHP_VERSION_ID < 70000
    zend_object *obj = zend_object_store_get_object((zval*) scope ZAI_TSRMLS_CC);

    if (obj->properties) {
        return *(zval **)obj->properties_table[info->offset];
    } else {
        return obj->properties_table[info->offset];
    }
#else
    return zai_symbol_lookup_return_zval(OBJ_PROP(Z_OBJ_P((zval*)scope), info->offset));
#endif
}

static inline zval* zai_symbol_lookup_local_frame(zend_execute_data *ex, zai_string_view *name ZAI_TSRMLS_DC) {
#if PHP_VERSION_ID < 70000
    zend_function *function = ex->function_state.function;
#else
    zend_function *function = ex->func;
#endif

    if (!function || function->type != ZEND_USER_FUNCTION) {
        return NULL;
    }

#if PHP_VERSION_ID >= 70000
    zval *local = NULL;
    for (int var = 0; var < function->op_array.last_var; var++) {
        zend_string *match = function->op_array.vars[var];

        if ((name->len == ZSTR_LEN(match)) && (memcmp(name->ptr, ZSTR_VAL(match), name->len) == SUCCESS)) {
            local = ZEND_CALL_VAR_NUM(ex, var);
            break;
        }
    }

    return zai_symbol_lookup_return_zval(local);
#else
    zval **local = NULL;
    zend_execute_data *frame = EG(current_execute_data);

    for (int var = 0; var < function->op_array.last_var; var++) {
        zend_compiled_variable *match = &function->op_array.vars[var];

        if ((name->len == (size_t) match->name_len) && (memcmp(name->ptr, match->name, name->len) == SUCCESS)) {
#ifdef EX_CV_NUM
            local = *EX_CV_NUM(frame, var);
#else
            local = frame->CVs[var];
#endif
            break;
        }
    }

    if (!local) {
        return NULL;
    }

    return *local;
#endif
}

static inline zval* zai_symbol_lookup_local_static(zend_function *function, zai_string_view *name) {
    if (function->type != ZEND_USER_FUNCTION || !function->op_array.static_variables) {
        return NULL;
    }

#ifdef ZEND_MAP_PTR_GET
    HashTable *table = ZEND_MAP_PTR_GET(function->op_array.static_variables_ptr);

    if (!table) {
        return NULL;
    }
#else
    HashTable *table = function->op_array.static_variables;
#endif

    zval *var = (zval*)
        zai_symbol_lookup_table(
            table,
            *name,
            false, false);

    return zai_symbol_lookup_return_zval(var);
}

static inline zval* zai_symbol_lookup_local_impl(
        zai_symbol_scope_t scope_type, void *scope,
        zai_string_view *name ZAI_TSRMLS_DC) {
    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_FRAME:
            return zai_symbol_lookup_local_frame(scope, name ZAI_TSRMLS_CC);

        case ZAI_SYMBOL_SCOPE_STATIC:
            return zai_symbol_lookup_local_static(scope, name);

        default:
            assert(0 && "local lookup may only be performed in frame and static scopes");
    }
    return NULL;
}

void* zai_symbol_lookup(zai_symbol_type_t symbol_type, zai_symbol_scope_t scope_type, void *scope, zai_string_view *name ZAI_TSRMLS_DC) {
    switch (symbol_type) {
        case ZAI_SYMBOL_TYPE_CLASS:
            return zai_symbol_lookup_class_impl(scope_type, scope, name ZAI_TSRMLS_CC);

        case ZAI_SYMBOL_TYPE_FUNCTION:
            return zai_symbol_lookup_function_impl(scope_type, scope, name ZAI_TSRMLS_CC);

        case ZAI_SYMBOL_TYPE_CONSTANT:
            return zai_symbol_lookup_constant_impl(scope_type, scope, name ZAI_TSRMLS_CC);

        case ZAI_SYMBOL_TYPE_PROPERTY:
            return zai_symbol_lookup_property_impl(scope_type, scope, name ZAI_TSRMLS_CC);

        case ZAI_SYMBOL_TYPE_LOCAL:
            return zai_symbol_lookup_local_impl(scope_type, scope, name ZAI_TSRMLS_CC);
    }

    return NULL;
}
// clang-format on
