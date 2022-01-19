#ifndef HAVE_ZAI_SYMBOLS_API_CONSTANT_H
#define HAVE_ZAI_SYMBOLS_API_CONSTANT_H
// clang-format off
static inline zval *zai_symbol_lookup_constant(
                        zai_symbol_scope_t scope_type, void *scope,
                        zai_string_view *name ZAI_TSRMLS_DC) {
    return (zval *)zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, scope_type, scope, name ZAI_TSRMLS_CC);
}

static inline zval *zai_symbol_lookup_constant_literal(const char *cn, size_t cnl ZAI_TSRMLS_DC) {
    zai_string_view vcn =
        (zai_string_view){cnl, cn};

    return zai_symbol_lookup_constant(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &vcn ZAI_TSRMLS_CC);
}

static inline zval *zai_symbol_lookup_constant_literal_ns(const char *ns, size_t nsl,
                                                          const char *cn, size_t cnl ZAI_TSRMLS_DC) {
    zai_string_view vns =
        (zai_string_view){nsl, ns};
    zai_string_view vcn =
        (zai_string_view){cnl, cn};

    return zai_symbol_lookup_constant(ZAI_SYMBOL_SCOPE_NAMESPACE, &vns, &vcn ZAI_TSRMLS_CC);
}
// clang-format on
#endif
