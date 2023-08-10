#ifndef HAVE_ZAI_SYMBOLS_API_FUNCTION_H
#define HAVE_ZAI_SYMBOLS_API_FUNCTION_H

static inline
zend_function *zai_symbol_lookup_function(zai_symbol_scope_t scope_type,
                                          void *scope,
                                          zai_string_view *name) {
    return (zend_function *)zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, scope_type, scope, name	);
}

static inline zend_function *zai_symbol_lookup_function_global(zai_string_view vfn) {
    return zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &vfn);
}

static inline
zend_function *zai_symbol_lookup_function_ns(zai_string_view vns, zai_string_view vfn) {
    return zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_NAMESPACE, &vns, &vfn);
}

#endif
