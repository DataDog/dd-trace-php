#ifndef ZAI_EXCEPTIONS_H
#define ZAI_EXCEPTIONS_H

#include <main/php.h>

#include <ext/standard/php_smart_str_public.h>
// dummy comment here to prevent clang format fixer from reordering includes here
#include <Zend/zend_exceptions.h>
#include <symbols/symbols.h>
#include <zai_string/string.h>

static inline zval *zai_exception_read_property(zval *objzv, const char *pn, size_t pnl ZAI_TSRMLS_DC) {
    zval *property = zai_symbol_lookup_property_literal(ZAI_SYMBOL_SCOPE_OBJECT, objzv, pn, pnl ZAI_TSRMLS_CC);

    if (!property) {
        return &EG(uninitialized_zval);
    }

    return property;
}

#define ZAI_EXCEPTION_PROPERTY(objzv, name) zai_exception_read_property(objzv, ZEND_STRL(name) ZAI_TSRMLS_CC)
zai_string_view zai_exception_message(zval *ex TSRMLS_DC);  // fallback string if message invalid
smart_str zai_get_trace_without_args(HashTable *trace);
smart_str zai_get_trace_without_args_from_exception(zval *ex TSRMLS_DC);

#endif  // ZAI_EXCEPTIONS_H
