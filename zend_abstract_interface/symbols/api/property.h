#ifndef HAVE_ZAI_SYMBOLS_API_PROPERTY_H
#define HAVE_ZAI_SYMBOLS_API_PROPERTY_H

static inline
zval *zai_symbol_lookup_property(zai_symbol_scope_t scope_type,
                                 void *scope,
                                 zai_string_view name) {
    return (zval *)zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, scope_type, scope, &name);
}

#endif
