#ifndef ZAI_PROPERTIES_H
#define ZAI_PROPERTIES_H

#include "Zend/zend_API.h"

// Note that the function will completely bypass read_property. ce (scope) must be object ce or parent of object ce.
zval *zai_read_property_direct(zend_class_entry *ce, zend_object *object, zend_string *name);

zval *zai_read_property_direct_cstr(zend_class_entry *ce, zend_object *obj, const char *name, int name_len);
#define zai_read_property_direct_literal(obj, ce, name) zai_read_property_direct_cstr(obj, ce, ZEND_STRL(name))

#endif  // ZAI_PROPERTIES_H
