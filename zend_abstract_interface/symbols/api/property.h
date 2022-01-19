#ifndef HAVE_ZAI_SYMBOLS_API_PROPERTY_H
#define HAVE_ZAI_SYMBOLS_API_PROPERTY_H
// clang-format off
static inline zval *zai_symbol_lookup_property(
                        zai_symbol_scope_t scope_type, void *scope,
                        zai_string_view *name ZAI_TSRMLS_DC) {
    return (zval *)zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, scope_type, scope, name ZAI_TSRMLS_CC);
}

static inline zval *zai_symbol_lookup_property_literal(zai_symbol_scope_t scope_type, void *scope, const char *pn, size_t pnl ZAI_TSRMLS_DC) {
    zai_string_view vpn =
        (zai_string_view) {pnl, pn};
    return zai_symbol_lookup_property(scope_type, scope, &vpn ZAI_TSRMLS_CC);
}
// clang-format on
#endif
