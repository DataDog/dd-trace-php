#include "engine_api.h"

extern inline zval ddtrace_zval_long(zend_long num);
extern inline zval ddtrace_zval_null(void);
extern inline zval ddtrace_zval_undef(void);

zval ddtrace_zval_stringl(const char *str, size_t len) {
    zval zv;
    ZVAL_STRINGL(&zv, str, len);
    return zv;
}

void ddtrace_write_property(zval *obj, const char *prop, size_t prop_len, zval *value) {
    zend_string *member = zend_string_init(prop, prop_len, 0);
    // the underlying API doesn't tell you if it worked _shrug_
    Z_OBJ_P(obj)->handlers->write_property(Z_OBJ_P(obj), member, value, NULL);
    zend_string_release(member);
}

// Modeled after PHP's property_exists for the Z_TYPE_P(object) == IS_OBJECT case
bool ddtrace_property_exists(zval *object, zval *property) {
    zend_class_entry *ce;
    zend_property_info *property_info;

    ZEND_ASSERT(Z_TYPE_P(object) == IS_OBJECT);
    ZEND_ASSERT(Z_TYPE_P(property) == IS_STRING);

    ce = Z_OBJCE_P(object);
    property_info = zend_hash_find_ptr(&ce->properties_info, Z_STR_P(property));
    if (property_info && (!(property_info->flags & ZEND_ACC_PRIVATE) || property_info->ce == ce)) {
        return true;
    }

    if (Z_OBJ_HANDLER_P(object, has_property)(Z_OBJ_P(object), Z_STR_P(property), 2, NULL)) {
        return true;
    }
    return false;
}

ZEND_RESULT_CODE ddtrace_read_property(zval *dest, zval *obj, const char *prop, size_t prop_len) {
    zval rv, member = ddtrace_zval_stringl(prop, prop_len);
    if (ddtrace_property_exists(obj, &member)) {
        zval *result = Z_OBJ_P(obj)->handlers->read_property(Z_OBJ_P(obj), Z_STR(member), BP_VAR_R, NULL, &rv);
        if (result) {
            zend_string_release(Z_STR(member));
            ZVAL_COPY(dest, result);
            return SUCCESS;
        }
    }
    zend_string_release(Z_STR(member));
    return FAILURE;
}
