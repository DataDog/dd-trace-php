#ifndef ZAI_EXCEPTIONS_H
#define ZAI_EXCEPTIONS_H

#include <main/php.h>
// dummy comment here to prevent clang format fixer from reordering includes here
#include <Zend/zend_exceptions.h>
#include <properties/properties.h>

static inline zend_class_entry *zai_get_exception_base(zend_object *object) {
    assert(instanceof_function(object->ce, zend_ce_throwable));
    return instanceof_function(object->ce, zend_ce_exception) ? zend_ce_exception : zend_ce_error;
}

#if PHP_VERSION_ID < 70100
#define ZEND_STR_MESSAGE "message"
#define ZEND_STR_CODE "code"

#define ZAI_EXCEPTION_PROPERTY(object, id) \
    zai_read_property_direct_cstr(zai_get_exception_base(object), object, ZEND_STRL(id))
#elif PHP_VERSION_ID < 70200
#define ZAI_EXCEPTION_PROPERTY(object, id) \
    zai_read_property_direct(zai_get_exception_base(object), object, CG(known_strings)[id])
#else
#define ZAI_EXCEPTION_PROPERTY(object, id) \
    zai_read_property_direct(zai_get_exception_base(object), object, ZSTR_KNOWN(id))
#endif

zend_string *zai_exception_message(zend_object *ex);  // fallback string if message invalid
zend_string *zai_get_trace_without_args(zend_array *trace);
zend_string *zai_get_trace_without_args_from_exception(zend_object *ex);

#endif  // ZAI_EXCEPTIONS_H
