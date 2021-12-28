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

static inline void *zai_symbol_lookup_table(HashTable *table, const char *name, size_t length, bool ncase, bool pointer ZAI_TSRMLS_DC) {
#if PHP_VERSION_ID >= 70000
    zval *result = zend_hash_str_find(table, name, length);
#else
    void *result = NULL;

    zend_hash_find(table, name, length + 1, (void **)&result);
#endif

    if (!result && ncase) {
        char *ptr = (char *)pemalloc(length + 1, 1);

        for (uint32_t c = 0; c < length; c++) {
            ptr[c] = tolower(name[c]);
        }

        ptr[length] = 0;

#if PHP_VERSION_ID >= 70000
        result = zend_hash_str_find(table, ptr, length);
#else
        zend_hash_find(table, ptr, length + 1, (void **)&result);
#endif

        pefree(ptr, 1);
    }

#if PHP_VERSION_ID >= 70000
    if (pointer && result) {
        return Z_PTR_P(result);
    } else {
        return result;
    }
#endif
    return result;
}

static inline size_t zai_symbol_lookup_lengthof(zai_string_view *view) {
    if (!view->len) {
        return 0;
    }

    if (memcmp(view->ptr, "\\", sizeof("\\")-1) == SUCCESS) {
        return view->len - 1;
    }

    return view->len;
}

static inline const char* zai_symbol_lookup_startof(zai_string_view *view) {
    if (!view->len) {
        return NULL;
    }

    if (memcmp(view->ptr, "\\", sizeof("\\")-1) == SUCCESS) {
        return view->ptr + 1;
    }

    return view->ptr;
}

static inline zai_string_view* zai_symbol_lookup_key(zai_string_view *namespace, zai_string_view *name, bool lower ZAI_TSRMLS_DC) {
    zai_string_view *result = pemalloc(sizeof(zai_string_view), 1);

    size_t namespace_length = zai_symbol_lookup_lengthof(namespace);
    size_t name_length      = zai_symbol_lookup_lengthof(name);

    const char* namespace_start   = zai_symbol_lookup_startof(namespace);
    const char* name_start        = zai_symbol_lookup_startof(name);

#define CHAR_AT(n) (((char*) result->ptr)[n])
    if (namespace_length) {
        result->len = namespace_length + name_length + sizeof("\\")-1;
        result->ptr = pemalloc(result->len + 1, 2);

        memcpy(&CHAR_AT(0), namespace_start, namespace_length);

        for (uint32_t c = 0; c < namespace_length; c++) {
            CHAR_AT(c) = tolower(CHAR_AT(c));
        }

        memcpy(&CHAR_AT(namespace_length), "\\", sizeof("\\")-1);
        memcpy(&CHAR_AT(namespace_length + (sizeof("\\")-1)), name_start, name_length);
    } else {
        result->len = name_length;
        result->ptr = pemalloc(result->len + 1, 1);

        memcpy(&CHAR_AT(0), name_start, name_length);
    }

    if (lower) {
        for (uint32_t c = namespace_length ? namespace_length + (sizeof("\\")-1) : 0;
                      c < result->len;
                      c++) {
            CHAR_AT(c) = tolower(CHAR_AT(c));
        }
    }

    CHAR_AT(result->len) = 0;
#undef CHAR_AT

    return result;
}

static inline zend_class_entry *zai_symbol_lookup_class_impl(zai_symbol_scope_t scope_type, void *scope, zai_string_view *name ZAI_TSRMLS_DC) {
    void *result = NULL;

    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_GLOBAL: {
            result = zai_symbol_lookup_table(
                EG(class_table),
                zai_symbol_lookup_startof(name),
                zai_symbol_lookup_lengthof(name),
                true, true ZAI_TSRMLS_CC);
        } break;

        case ZAI_SYMBOL_SCOPE_NAMESPACE: {
            zai_string_view *key = zai_symbol_lookup_key(scope, name, true ZAI_TSRMLS_CC);

            if (key) {
                result = zai_symbol_lookup_table(
                    EG(class_table),
                    key->ptr,
                    key->len,
                    false, true ZAI_TSRMLS_CC);

                pefree((char*) key->ptr, 1);
                pefree(key, 1);
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
    zend_function *result;
    HashTable *table = NULL;
    zai_string_view *key = name;

    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_CLASS:
            table = &((zend_class_entry *)scope)->function_table;
            break;

        case ZAI_SYMBOL_SCOPE_OBJECT:
            table = &Z_OBJCE_P((zval *)scope)->function_table;
            break;

        case ZAI_SYMBOL_SCOPE_NAMESPACE:
            key = zai_symbol_lookup_key(scope, name, true ZAI_TSRMLS_CC);
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
        key == name ? zai_symbol_lookup_startof(key) : key->ptr,
        key == name ? zai_symbol_lookup_lengthof(key) : key->len,
        scope_type != ZAI_SYMBOL_SCOPE_NAMESPACE, true ZAI_TSRMLS_CC);

    if (key != name) {
        pefree((char*) key->ptr, 1);
        pefree(key, 1);
    }

    return function;
}

static inline zval* zai_symbol_lookup_constant_global(zai_symbol_scope_t scope_type, void *scope, zai_string_view *name ZAI_TSRMLS_DC) {
    zai_string_view *key = name;

    if (scope_type == ZAI_SYMBOL_SCOPE_NAMESPACE) {
        key = zai_symbol_lookup_key(scope, name, false ZAI_TSRMLS_CC);
    }

    zend_constant *constant = zai_symbol_lookup_table(
        EG(zend_constants),
        key == name ? zai_symbol_lookup_startof(key) : key->ptr,
        key == name ? zai_symbol_lookup_lengthof(key) : key->len,
        false, true ZAI_TSRMLS_CC);

    if (key != name) {
        pefree((char*) key->ptr, 1);
        pefree(key, 1);
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
    }

    if (!zai_symbol_update(ce ZAI_TSRMLS_CC)) {
        return NULL;
    }

#if PHP_VERSION_ID >= 70100
    zend_class_constant *constant = zai_symbol_lookup_table(&ce->constants_table, name->ptr, name->len, false, true ZAI_TSRMLS_CC);

    if (!constant) {
        return NULL;
    }

    return &constant->value;
#elif PHP_VERSION_ID >= 70000
    zval *constant = zai_symbol_lookup_table(&ce->constants_table, name->ptr, name->len, false, false ZAI_TSRMLS_CC);

    if (!constant) {
        return NULL;
    }

    return constant;
#else
    zval **constant = zai_symbol_lookup_table(&ce->constants_table, name->ptr, name->len, false, false ZAI_TSRMLS_CC);

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

    info = zai_symbol_lookup_table(&ce->properties_info, name->ptr, name->len, false, true ZAI_TSRMLS_CC);

    if (!info) {
        if (scope_type == ZAI_SYMBOL_SCOPE_OBJECT) {
#if PHP_VERSION_ID < 70000
            zend_object *obj = zend_object_store_get_object((zval*) scope ZAI_TSRMLS_CC);
#else
            zend_object *obj = Z_OBJ_P((zval*) scope);
#endif

            void *property = zai_symbol_lookup_table(
                obj->properties,
                name->ptr, name->len,
                false, false ZAI_TSRMLS_CC);

#if PHP_VERSION_ID < 70000
            if (!property) {
                return NULL;
            }

            return *(zval**) property;
#else
            return (zval*) property;
#endif
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

        zval *property = CE_STATIC_MEMBERS(ce) + info->offset;

        while (Z_TYPE_P(property) == IS_INDIRECT) {
            property = Z_INDIRECT_P(property);
        }

        return property;
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
    return OBJ_PROP(Z_OBJ_P((zval*)scope), info->offset);
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
    for (uint32_t var = 0; var < function->op_array.last_var; var++) {
        zend_string *match = function->op_array.vars[var];

        if ((name->len == ZSTR_LEN(match)) && (memcmp(name->ptr, ZSTR_VAL(match), name->len) == SUCCESS)) {
            local = ZEND_CALL_VAR_NUM(ex, var);
            break;
        }
    }

    if (!local) {
        return NULL;
    }

    ZVAL_DEREF(local);

    return local;
#else
    zval **local = NULL;
    zend_execute_data *frame = EG(current_execute_data);

    for (int var = 0; var < function->op_array.last_var; var++) {
        zend_compiled_variable *match = &function->op_array.vars[var];

        if ((name->len == match->name_len) && (memcmp(name->ptr, match->name, name->len) == SUCCESS)) {
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

static inline zval* zai_symbol_lookup_local_static(zend_function *function, zai_string_view *name ZAI_TSRMLS_DC) {
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

    zval *var = (zval*) zai_symbol_lookup_table(table, name->ptr, name->len, false, false ZAI_TSRMLS_CC);

    if (!var) {
        return NULL;
    }

#if PHP_VERSION_ID >= 70000
    ZVAL_DEREF(var);

    return var;
#else
    return *(zval**) var;
#endif
}

static inline zval* zai_symbol_lookup_local_impl(
        zai_symbol_scope_t scope_type, void *scope,
        zai_string_view *name ZAI_TSRMLS_DC) {
    switch (scope_type) {
        case ZAI_SYMBOL_SCOPE_FRAME:
            return zai_symbol_lookup_local_frame(scope, name ZAI_TSRMLS_CC);

        case ZAI_SYMBOL_SCOPE_STATIC:
            return zai_symbol_lookup_local_static(scope, name ZAI_TSRMLS_CC);

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
}
// clang-format on
