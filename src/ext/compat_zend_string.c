#include "compat_zend_string.h"
#include "Zend/zend.h"
#include "Zend/zend_API.h"
#include "Zend/zend_types.h"
#include "php_version.h"

#if PHP_VERSION_ID < 70000
zval *ddtrace_string_tolower(zval *str) {
    if (!str) {
        return NULL;
    }
    zval *ret;
    ALLOC_INIT_ZVAL(ret);

    ZVAL_STRINGL(ret, zend_str_tolower_dup(Z_STRVAL_P(str), Z_STRLEN_P(str)), Z_STRLEN_P(str), 0);
    return ret;
}

void ddtrace_downcase_zval(zval *src) {
    if (!src || Z_TYPE_P(src) != IS_STRING) {
        return;
    }

    zend_str_tolower(Z_STRVAL_P(src), Z_STRLEN_P(src));
}

#else
zval *ddtrace_string_tolower(zval *str) {
    if (!str || Z_TYPE_P(str) != IS_STRING) {
        return NULL;
    }
    zend_string *original_str = Z_STR_P(str);
    // zval *val = emalloc(sizeof(zval));
    // *val = EG(uninitialized_zval);
    ZVAL_STR(str, zend_string_tolower(original_str));
    zend_string_release(original_str);
    return str;
}

void ddtrace_downcase_zval(zval *src) {
    if (!src || Z_TYPE_P(src) != IS_STRING) {
        return;
    }
    zend_string *str = Z_STR_P(src);

    ZVAL_STR(src, zend_string_tolower(str));
    zend_string_release(str);
    return src;
}
#endif
