#ifndef ZAI_EXCEPTIONS_H
#define ZAI_EXCEPTIONS_H

#include <main/php.h>
// dummy comment here to prevent clang format fixer from reordering includes here
#include <Zend/zend_exceptions.h>
#include <symbols/symbols.h>
#include <zai_string/string.h>

static inline zend_class_entry *zai_get_exception_base(zend_object *object) {
    assert(instanceof_function(object->ce, zend_ce_throwable));
    return instanceof_function(object->ce, zend_ce_exception) ? zend_ce_exception : zend_ce_error;
}

static inline zval *zai_exception_read_property_str(zend_object *object, const char *pn, size_t pnl) {
    zval zv;

    ZVAL_OBJ(&zv, object);

    zval *property = zai_symbol_lookup_property(ZAI_SYMBOL_SCOPE_OBJECT, &zv, (zai_str)ZAI_STR_NEW(pn, pnl));

    if (!property) {
        return &EG(uninitialized_zval);
    }

    return property;
}

static inline zval *zai_exception_read_property(zend_object *object, zend_string *name) {
    zval zv;

    ZVAL_OBJ(&zv, object);

    zval *property = zai_symbol_lookup_property(ZAI_SYMBOL_SCOPE_OBJECT, &zv, (zai_str)ZAI_STR_FROM_ZSTR(name));

    if (!property) {
        return &EG(uninitialized_zval);
    }

    return property;
}

#if PHP_VERSION_ID < 70100
#define ZAI_EXCEPTION_PROPERTY(object, id) zai_exception_read_property_str(object, ZEND_STRL(id))
#elif PHP_VERSION_ID < 70200
#define ZAI_EXCEPTION_PROPERTY(object, id) zai_exception_read_property(object, CG(known_strings)[id])
#else
#define ZAI_EXCEPTION_PROPERTY(object, id) zai_exception_read_property(object, ZSTR_KNOWN(id))
#endif

zend_string *zai_exception_message(zend_object *ex);  // fallback string if message invalid
zend_string *zai_get_trace_without_args(zend_array *trace);
zend_string *zai_get_trace_without_args_from_exception(zend_object *ex);
zend_string *zai_get_trace_without_args_skip_frames(zend_array *trace, int skip);
zend_string *zai_get_trace_without_args_from_exception_skip_frames(zend_object *ex, int skip);

#endif  // ZAI_EXCEPTIONS_H
