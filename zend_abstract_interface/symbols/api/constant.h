#ifndef HAVE_ZAI_SYMBOLS_API_CONSTANT_H
#define HAVE_ZAI_SYMBOLS_API_CONSTANT_H

static inline
zval *zai_symbol_lookup_constant(zai_symbol_scope_t scope_type,
                                 void *scope,
                                 zai_str name) {
    return (zval *)zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, scope_type, scope, &name);
}

static inline zval *zai_symbol_lookup_constant_global(zai_str vcn) {
    return zai_symbol_lookup_constant(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, vcn);
}

static inline
zval *zai_symbol_lookup_constant_ns(zai_str vns, zai_str vcn) {
    return zai_symbol_lookup_constant(ZAI_SYMBOL_SCOPE_NAMESPACE, &vns, vcn);
}

#endif
