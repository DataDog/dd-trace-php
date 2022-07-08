#include <include/exceptions.h>

zend_class_entry *tea_exception_throw(const char *message) {
    zend_class_entry *ce = zend_exception_get_default();
    zend_throw_exception(ce, (char *)message, 0);
    return ce;
}

bool tea_exception_eq(zend_class_entry *ce, const char *message) {
    if (!tea_exception_exists()) return false;
    if (ce != EG(exception)->ce) return false;

#if PHP_VERSION_ID >= 80000
    zval rv;
    zval *zmsg = zend_read_property_ex(ce, EG(exception), ZSTR_KNOWN(ZEND_STR_MESSAGE), 1, &rv);
#else
    zval obj, rv;
    ZVAL_OBJ(&obj, EG(exception));

    zval *zmsg = zend_read_property(ce, &obj, "message", (sizeof("message") - 1), 1, &rv);
#endif

    if (!zmsg && Z_TYPE_P(zmsg) != IS_STRING) return false;
    return strcmp(Z_STRVAL_P(zmsg), message) == 0;
}

bool tea_exception_exists() { return EG(exception) != NULL; }

void tea_exception_ignore() { zend_clear_exception(); }
