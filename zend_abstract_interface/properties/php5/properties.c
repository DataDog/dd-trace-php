#include "properties.h"

#include <main/php.h>

zval *zai_read_property_direct(zend_class_entry *ce, zval *objzv, const char *name, int name_len TSRMLS_DC) {
    if (!ce) {
        return &EG(error_zval);
    }
    if (!objzv) {
        return &EG(error_zval);
    }
    if (!name) {
        return &EG(error_zval);
    }
    if (!instanceof_function(Z_OBJCE_P(objzv), ce TSRMLS_CC)) {
        return &EG(error_zval);
    }

    zend_object *obj = (zend_object *)zend_object_store_get_object(objzv TSRMLS_CC);

    zval namezv;
    ZVAL_STRINGL(&namezv, name, name_len, 0);

    zend_class_entry *prev_fake_scope = EG(scope);
    EG(scope) = ce;
    zend_property_info *prop_info = zend_get_property_info(obj->ce, &namezv, 1 TSRMLS_CC);
    EG(scope) = prev_fake_scope;

    if (UNEXPECTED(prop_info == NULL)) {
        return &EG(uninitialized_zval);
    }

    if (prop_info->offset >= 0) {
        if (obj->properties) {
            return *(zval **)obj->properties_table[prop_info->offset];
        } else {
            return obj->properties_table[prop_info->offset];
        }
    }

    zval **value;
    if (obj->properties && zend_hash_quick_find(obj->properties, prop_info->name, prop_info->name_length + 1,
                                                prop_info->h, (void **)&value) == SUCCESS) {
        return *value;
    }

    return &EG(uninitialized_zval);
}
