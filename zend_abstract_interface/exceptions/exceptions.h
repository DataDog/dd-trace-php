#ifndef ZAI_EXCEPTIONS_H
#define ZAI_EXCEPTIONS_H

#include <main/php.h>
// dummy comment here to prevent clang format fixer from reordering includes here
#include <Zend/zend_exceptions.h>
#include <symbols/symbols.h>

static inline zend_class_entry *zai_get_exception_base(zend_object *object) {
    assert(instanceof_function(object->ce, zend_ce_throwable));
    return instanceof_function(object->ce, zend_ce_exception) ? zend_ce_exception : zend_ce_error;
}

#if PHP_VERSION_ID < 70100
#define ZEND_STR_MESSAGE "message"
#define ZEND_STR_CODE "code"
static inline zval *zai_exception_read_property(zend_object *object, const char *pn, size_t pnl) {
    zval zv;

    ZVAL_OBJ(&zv, object);

    zval *property = zai_symbol_lookup_property_literal(ZAI_SYMBOL_SCOPE_OBJECT, &zv, pn, pnl);

    if (!property) {
        return &EG(uninitialized_zval);
    }

    return property;
}
#define ZAI_EXCEPTION_PROPERTY(object, id) zai_exception_read_property(object, ZEND_STRL(id))
#else
static inline zval *zai_exception_read_property(zend_object *object, zend_string *name) {
    zval zv;

    ZVAL_OBJ(&zv, object);

    zval *property = zai_symbol_lookup_property_literal(ZAI_SYMBOL_SCOPE_OBJECT, &zv, ZSTR_VAL(name), ZSTR_LEN(name));

    if (!property) {
        return &EG(uninitialized_zval);
    }

    return property;
}
#if PHP_VERSION_ID < 70200
#define ZAI_EXCEPTION_PROPERTY(object, id) zai_exception_read_property(object, CG(known_strings)[id])
#else
#define ZAI_EXCEPTION_PROPERTY(object, id) zai_exception_read_property(object, ZSTR_KNOWN(id))
#endif
#endif

zend_string *zai_exception_message(zend_object *ex);  // fallback string if message invalid
zend_string *zai_get_trace_without_args(zend_array *trace);
zend_string *zai_get_trace_without_args_from_exception(zend_object *ex);

#endif  // ZAI_EXCEPTIONS_H
