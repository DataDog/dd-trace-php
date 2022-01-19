#include <include/exceptions.h>

zend_class_entry *tea_exception_throw(const char *message TEA_TSRMLS_DC) {
    zend_class_entry *ce = zend_exception_get_default(TEA_TSRMLS_C);
    zend_throw_exception(ce, (char *)message, 0 TEA_TSRMLS_CC);
    return ce;
}

bool tea_exception_eq(zend_class_entry *ce, const char *message TEA_TSRMLS_DC) {
    if (!tea_exception_exists(TEA_TSRMLS_C)) return false;
#if PHP_VERSION_ID >= 70000
    if (ce != EG(exception)->ce) return false;
#else
    if (ce != Z_OBJCE_P(EG(exception))) return false;
#endif

#if PHP_VERSION_ID >= 80000
    zval rv;
    zval *zmsg = zend_read_property_ex(ce, EG(exception), ZSTR_KNOWN(ZEND_STR_MESSAGE), 1, &rv);
#elif PHP_VERSION_ID >= 70000
    zval obj, rv;
    ZVAL_OBJ(&obj, EG(exception));

    zval *zmsg = zend_read_property(ce, &obj, "message", (sizeof("message") - 1), 1, &rv);
#else
    zval *zmsg = zend_read_property(ce, EG(exception), "message", (sizeof("message") - 1), 1 TEA_TSRMLS_CC);
#endif

    if (!zmsg && Z_TYPE_P(zmsg) != IS_STRING) return false;
    return strcmp(Z_STRVAL_P(zmsg), message) == 0;
}

bool tea_exception_exists(TEA_TSRMLS_D) { return EG(exception) != NULL; }

#if PHP_VERSION_ID >= 70000
void tea_exception_ignore(TEA_TSRMLS_D) { zend_clear_exception(); }
#else
void tea_exception_ignore(TEA_TSRMLS_D) {
    /* There might not be an active execution context when we clear the
     * exception and in that case EG(current_execute_data) will be NULL. On
     * PHP 5, zend_clear_exception() does not perform a NULL check before
     * setting the opline. Otherwise we would use zend_clear_exception() to
     * free the unhandled exception here.
     */
    if (EG(exception)) {
        zval_ptr_dtor(&EG(exception));
        EG(exception) = NULL;
        if (EG(prev_exception)) {
            zval_ptr_dtor(&EG(prev_exception));
            EG(prev_exception) = NULL;
        }
        if (EG(current_execute_data)) {
            EG(current_execute_data)->opline = EG(opline_before_exception);
        }
    }
}
#endif
