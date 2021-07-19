#include "properties.h"

#include <main/php.h>

#if PHP_VERSION_ID < 70100
#define fake_scope scope
#endif

zval *zai_read_property_direct(zend_class_entry *ce, zend_object *object, zend_string *name) {
    if (!ce) {
        return &EG(error_zval);
    }
    if (!object) {
        return &EG(error_zval);
    }
    if (!name) {
        return &EG(error_zval);
    }
    if (!instanceof_function(object->ce, ce)) {
        return &EG(error_zval);
    }

    zend_class_entry *prev_fake_scope = EG(fake_scope);
    EG(fake_scope) = ce;
    zend_property_info *prop_info = zend_get_property_info(object->ce, name, 1);
    EG(fake_scope) = prev_fake_scope;

    if (UNEXPECTED(prop_info == NULL)) {
        if (object->properties == NULL) {
            return &EG(uninitialized_zval);
        }
        zval *zv = zend_hash_find(object->properties, name);
        if (zv == NULL) {
            return &EG(uninitialized_zval);
        }
        return zv;
    }

    if (UNEXPECTED(prop_info == ZEND_WRONG_PROPERTY_INFO)) {
        return &EG(error_zval);
    }

    return OBJ_PROP(object, prop_info->offset);
}

zval *zai_read_property_direct_cstr(zend_class_entry *ce, zend_object *obj, const char *name, int name_len) {
    zend_string *name_str;
    ALLOCA_FLAG(use_heap);
    ZSTR_ALLOCA_INIT(name_str, name, name_len, use_heap);

    zval *val = zai_read_property_direct(ce, obj, name_str);

    ZSTR_ALLOCA_FREE(name_str, use_heap);

    return val;
}
