#ifndef ZAI_PROPERTIES_H
#define ZAI_PROPERTIES_H

#include "Zend/zend_API.h"

// Note that the function will completely bypass read_property. ce (scope) must be object ce or parent of object ce.
zval *zai_read_property_direct(zend_class_entry *ce, zval *objzv, const char *name, int name_len TSRMLS_DC);
#define zai_read_property_direct_literal(objzv, ce, name) zai_read_property_direct(objzv, ce, ZEND_STRL(name) TSRMLS_CC)

#endif  // ZAI_PROPERTIES_H
