#include "../tsrmls_cache.h"
#include <sandbox/sandbox.h>

#include "symbols.h"
#include "zend_compile.h"

// clang-format off
static inline bool zai_symbol_update(zend_class_entry *ce) {
    if (ce->ce_flags & ZEND_ACC_CONSTANTS_UPDATED) {
        return true;
    }

    volatile bool zai_symbol_updated = true;

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    zend_try {
        if (zend_update_class_constants(ce) != SUCCESS) {
            zai_symbol_updated = false;
        }
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

static inline void *zai_symbol_lookup_table(HashTable *table, zai_str key, bool ncase, bool pointer) {
    zval *result;
#if PHP_VERSION_ID >= 70300
    zval resultzv;
    zend_function *func;
    if (table == EG(function_table) && (func = zend_fetch_function_str(key.ptr, key.len))) {
        ZEND_ASSERT(pointer == true);
        result = &resultzv;
        ZVAL_PTR(result, func);
    } else
#endif
    result = zend_hash_str_find(table, key.ptr, key.len);

    if (!result && ncase) {
        char *ptr = (char *)pemalloc(key.len + 1, 1);

        for (uint32_t c = 0; c < key.len; c++) {
            ptr[c] = tolower(key.ptr[c]);
        }

        ptr[key.len] = 0;

#if PHP_VERSION_ID >= 70300
        if (table == EG(function_table) && (func = zend_fetch_function_str(key.ptr, key.len))) {
            ZEND_ASSERT(pointer == true);
            result = &resultzv;
            ZVAL_PTR(result, func);
        } else
#endif
        result = zend_hash_str_find(table, ptr, key.len);

        pefree(ptr, 1);
    }

    if (pointer && result) {
        return Z_PTR_P(result);
    } else {
        return result;
    }
}

static inline zai_str zai_symbol_lookup_clean(zai_str view) {
    if (!view.len || *view.ptr != '\\') {
        return view;
    }
    return (zai_str)ZAI_STR_NEW(view.ptr + 1, view.len - 1);
}

static zai_string zai_symbol_lookup_key(zai_str *namespace, zai_str *name, bool lower) {
    zai_str vns         = zai_symbol_lookup_clean(*namespace);
    zai_str separator   = ZAI_STR_EMPTY;
    if (vns.len) {
        separator = (zai_str)ZAI_STRL("\\");
    }
    zai_str vn          = zai_symbol_lookup_clean(*name);
    zai_string rv       = zai_string_concat3(vns, separator, vn);

    // Namespaces are never case-sensitive, so they are always lowered, even
    // if `lower == false`, but do not lowercase the name segment unless
    // `lower == true`.
    size_t len = vns.len + separator.len + (lower ? vn.len : 0);
    for (size_t c = 0; c < len; c++) {
        rv.ptr[c] = tolower(rv.ptr[c]);
    }

    return rv;
}

static inline zval* zai_symbol_lookup_return_zval(zval *zv) {
    if (!zv) {
        return NULL;
    }

    while (Z_TYPE_P(zv) == IS_INDIRECT) {
        zv = Z_INDIRECT_P(zv);
    }

    ZVAL_DEREF(zv);

    return zv;
}

static inline zend_class_entry *zai_symbol_lookup_class_impl(zai_symbol_scope_t scope_type, void *scope, zai_str *name) {
    void *result = NULL;

    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_GLOBAL: {
            result = zai_symbol_lookup_table(
                EG(class_table),
                zai_symbol_lookup_clean(*name),
                true, true);
        } break;

        case ZAI_SYMBOL_SCOPE_NAMESPACE: {
            zai_string key = zai_symbol_lookup_key(scope, name, true);
            result = zai_symbol_lookup_table(EG(class_table),zai_string_as_str(&key),false, true);
            zai_string_destroy(&key);
        } break;

        default:
            assert(0 && "class lookup may only be performed in global and namespace scopes");
    }

    return result;
}

static inline zend_function *zai_symbol_lookup_function_impl(zai_symbol_scope_t scope_type, void *scope, zai_str *name) {
    HashTable *table = NULL;
    zai_str key = *name;
    zai_string tmpkey = ZAI_STRING_EMPTY;

    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_CLASS:
            table = &((zend_class_entry *)scope)->function_table;
            break;

        case ZAI_SYMBOL_SCOPE_OBJECT:
            table = &Z_OBJCE_P((zval *)scope)->function_table;
            break;

        case ZAI_SYMBOL_SCOPE_NAMESPACE:
            tmpkey = zai_symbol_lookup_key(scope, name, true);
            key = zai_string_as_str(&tmpkey);
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

    zai_string_destroy(&tmpkey);

    return function;
}

static inline zval* lookup_constant_global(zai_symbol_scope_t scope_type, void *scope, zai_str *name) {
    zai_str key = *name;
    zai_string tmpkey = ZAI_STRING_EMPTY;

    if (scope_type == ZAI_SYMBOL_SCOPE_NAMESPACE) {
        tmpkey = zai_symbol_lookup_key(scope, name, false);
        key = zai_string_as_str(&tmpkey);
    }

    zend_constant *constant = zai_symbol_lookup_table(
        EG(zend_constants),
        key.ptr == name->ptr ? zai_symbol_lookup_clean(key) : key,
        false, true);

    zai_string_destroy(&tmpkey);

    if (!constant) {
        return NULL;
    }

    return &constant->value;
}

static inline zval* zai_symbol_lookup_constant_class(zai_symbol_scope_t scope_type, void *scope, zai_str *name) {
    zend_class_entry *ce;

    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_CLASS:
            ce = (zend_class_entry*) scope;
        break;

        case ZAI_SYMBOL_SCOPE_OBJECT:
            ce = Z_OBJCE_P((zval*) scope);
        break;

        default: /* unreachable */
            ce = NULL;
        break;
    }

    if (!zai_symbol_update(ce)) {
        return NULL;
    }

#if PHP_VERSION_ID >= 70100
    zend_class_constant *constant = zai_symbol_lookup_table(&ce->constants_table, *name, false, true);

    if (!constant) {
        return NULL;
    }

    return &constant->value;
#else
    zval *constant = zai_symbol_lookup_table(&ce->constants_table, *name, false, false);

    if (!constant) {
        return NULL;
    }

    return constant;
#endif
}

static inline zval* zai_symbol_lookup_constant_impl(
        zai_symbol_scope_t scope_type, void *scope,
        zai_str *name) {
    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_GLOBAL:
        case ZAI_SYMBOL_SCOPE_NAMESPACE:
            return lookup_constant_global(scope_type, scope, name	);

        case ZAI_SYMBOL_SCOPE_CLASS:
        case ZAI_SYMBOL_SCOPE_OBJECT:
            return zai_symbol_lookup_constant_class(scope_type, scope, name	);

        default:
            assert(0 && "constant lookup may not be performed in static and frame scopes");
            return NULL;
    }
}

static inline zval* zai_symbol_lookup_property_impl(
        zai_symbol_scope_t scope_type, void *scope,
        zai_str *name) {
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
            zend_object *obj = Z_OBJ_P((zval*) scope);
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

        if (!zai_symbol_update(ce)) {
            return NULL;
        }

#if PHP_VERSION_ID >= 70300
        if (CE_STATIC_MEMBERS(ce) == NULL) {
            zend_class_init_statics(ce);
        }
#endif
        return zai_symbol_lookup_return_zval(CE_STATIC_MEMBERS(ce) + info->offset);
    }

    return zai_symbol_lookup_return_zval(OBJ_PROP(Z_OBJ_P((zval*)scope), info->offset));
}

static inline zval* zai_symbol_lookup_local_frame(zend_execute_data *ex, zai_str *name) {
    zend_function *function = ex->func;

    if (!function || function->type != ZEND_USER_FUNCTION) {
        return NULL;
    }

    zval *local = NULL;
    for (int var = 0; var < function->op_array.last_var; var++) {
        zend_string *match = function->op_array.vars[var];

        if ((name->len == ZSTR_LEN(match)) && (memcmp(name->ptr, ZSTR_VAL(match), name->len) == SUCCESS)) {
            local = ZEND_CALL_VAR_NUM(ex, var);
            break;
        }
    }

    return zai_symbol_lookup_return_zval(local);
}

static inline zval* zai_symbol_lookup_local_static(zend_function *function, zai_str *name) {
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
        zai_str *name) {
    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_FRAME:
            return zai_symbol_lookup_local_frame(scope, name);

        case ZAI_SYMBOL_SCOPE_STATIC:
            return zai_symbol_lookup_local_static(scope, name);

        default:
            assert(0 && "local lookup may only be performed in frame and static scopes");
    }
    return NULL;
}

void* zai_symbol_lookup(zai_symbol_type_t symbol_type, zai_symbol_scope_t scope_type, void *scope, zai_str *name) {
    switch (symbol_type) {
        case ZAI_SYMBOL_TYPE_CLASS:
            return zai_symbol_lookup_class_impl(scope_type, scope, name);

        case ZAI_SYMBOL_TYPE_FUNCTION:
            return zai_symbol_lookup_function_impl(scope_type, scope, name);

        case ZAI_SYMBOL_TYPE_CONSTANT:
            return zai_symbol_lookup_constant_impl(scope_type, scope, name);

        case ZAI_SYMBOL_TYPE_PROPERTY:
            return zai_symbol_lookup_property_impl(scope_type, scope, name);

        case ZAI_SYMBOL_TYPE_LOCAL:
            return zai_symbol_lookup_local_impl(scope_type, scope, name);
    }

    return NULL;
}
// clang-format on
