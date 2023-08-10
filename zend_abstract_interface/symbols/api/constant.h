#ifndef HAVE_ZAI_SYMBOLS_API_CONSTANT_H
#define HAVE_ZAI_SYMBOLS_API_CONSTANT_H

static inline
zval *zai_symbol_lookup_constant(zai_symbol_scope_t scope_type,
                                 void *scope,
                                 zai_string_view name) {
    return (zval *)zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, scope_type, scope, &name);
}

static inline zval *zai_symbol_lookup_constant_global(zai_string_view vcn) {
    return zai_symbol_lookup_constant(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, vcn);
}

static inline
zval *zai_symbol_lookup_constant_ns(zai_string_view vns, zai_string_view vcn) {
    return zai_symbol_lookup_constant(ZAI_SYMBOL_SCOPE_NAMESPACE, &vns, vcn);
}

#endif
