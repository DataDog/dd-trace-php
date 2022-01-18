#ifndef HAVE_ZAI_SYMBOLS_API_FUNCTION_H
#define HAVE_ZAI_SYMBOLS_API_FUNCTION_H
// clang-format off
static inline zend_function *zai_symbol_lookup_function(
                                zai_symbol_scope_t scope_type, void *scope,
                                zai_string_view *name ZAI_TSRMLS_DC) {
    return (zend_function *)zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, scope_type, scope, name ZAI_TSRMLS_CC);
}

static inline zend_function *zai_symbol_lookup_function_literal(const char *fn, size_t fnl ZAI_TSRMLS_DC) {
    zai_string_view vfn =
        (zai_string_view){fnl, fn};

    return zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &vfn ZAI_TSRMLS_CC);
}

static inline zend_function *zai_symbol_lookup_function_literal_ns(const char *ns, size_t nsl,
                                                                   const char *fn, size_t fnl ZAI_TSRMLS_DC) {
    zai_string_view vns =
        (zai_string_view){nsl, ns};
    zai_string_view vfn =
        (zai_string_view){fnl, fn};

    return zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_NAMESPACE, &vns, &vfn ZAI_TSRMLS_CC);
}
// clang-format on
#endif
