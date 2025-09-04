#ifndef ZAI_EXCEPTIONS_H
#define ZAI_EXCEPTIONS_H

#include <main/php.h>
// dummy comment here to prevent clang format fixer from reordering includes here
#include <Zend/zend_exceptions.h>
#include <zai_string/string.h>

static inline zend_class_entry *zai_get_exception_base(zend_object *object) {
    assert(instanceof_function(object->ce, zend_ce_throwable));
    return instanceof_function(object->ce, zend_ce_exception) ? zend_ce_exception : zend_ce_error;
}

static inline zval *zai_exception_read_property_ex(zend_object *object, zend_property_info *info) {
    return OBJ_PROP(object, info->offset);
}

static inline zval *zai_exception_read_property_str(zend_object *object, const char *prop_name, size_t prop_name_len) {
    return zai_exception_read_property_ex(object, (zend_property_info *)zend_hash_str_find_ptr(&object->ce->properties_info, prop_name, prop_name_len));
}

static inline zval *zai_exception_read_property(zend_object *object, zend_string *prop_name) {
    return zai_exception_read_property_ex(object, (zend_property_info *)zend_hash_find_ptr(&object->ce->properties_info, prop_name));
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
