#include "compat_zend_string.h"

#include "Zend/zend.h"
#include "Zend/zend_API.h"
#include "Zend/zend_types.h"
#include "php_version.h"

#if PHP_VERSION_ID < 70000
void ddtrace_downcase_zval(zval *src) {
    if (!src || Z_TYPE_P(src) != IS_STRING) {
        return;
    }

    zend_str_tolower(Z_STRVAL_P(src), Z_STRLEN_P(src));
}

#else
void ddtrace_downcase_zval(zval *src) {
    if (!src || Z_TYPE_P(src) != IS_STRING) {
        return;
    }
    zend_string *str = Z_STR_P(src);

    ZVAL_STR(src, zend_string_tolower(str));
    zend_string_release(str);
}
#endif
