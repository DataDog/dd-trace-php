#include "compat_string.h"

#include <Zend/zend_API.h>
#include <php.h>
#include <php_version.h>

#include "compatibility.h"

size_t ddtrace_spprintf(char **message, size_t max_len, char *format, ...) {
    va_list arg;
    size_t len;

    va_start(arg, format);
    len = vspprintf(message, max_len, format, arg);
    va_end(arg);
    return len;
}

void ddtrace_downcase_zval(zval *src) {
    if (!src || Z_TYPE_P(src) != IS_STRING) {
        return;
    }
    zend_string *str = Z_STR_P(src);

    ZVAL_STR(src, zend_string_tolower(str));
    zend_string_release(str);
}

/* zend_operators.h wrongfully defines _convert_to_string, so use the ddtrace
 * prefix even though its private to this translation unit */
static zend_string *_ddtrace_convert_to_string(zval *op) {
try_again:
    switch (Z_TYPE_P(op)) {
        case IS_UNDEF:
            return zend_string_init("(undef)", sizeof("(undef)") - 1, 0);

        case IS_NULL:
            return zend_string_init("(null)", sizeof("(null)") - 1, 0);

        case IS_FALSE:
            return zend_string_init("(false)", sizeof("(false)") - 1, 0);

        case IS_TRUE:
            return zend_string_init("(true)", sizeof("(true)") - 1, 0);

        case IS_RESOURCE:
            return strpprintf(0, "Resource id #" ZEND_LONG_FMT, (zend_long)Z_RES_HANDLE_P(op));

        case IS_LONG:
            return zend_long_to_str(Z_LVAL_P(op));

        case IS_DOUBLE:
            return strpprintf(0, "%.*G", (int)EG(precision), Z_DVAL_P(op));

        case IS_ARRAY:
#if PHP_VERSION_ID < 70400
            return zend_string_init("Array", sizeof("Array") - 1, 0);
#else
            return ZSTR_KNOWN(ZEND_STR_ARRAY_CAPITALIZED);
#endif

        case IS_OBJECT: {
            zval tmp;
            if (Z_OBJ_HT_P(op)->cast_object) {
                if (Z_OBJ_HT_P(op)->cast_object(op, &tmp, IS_STRING) == SUCCESS) {
                    return Z_STR(tmp);
                }
            } else if (Z_OBJ_HT_P(op)->get) {
                zval *z = Z_OBJ_HT_P(op)->get(op, &tmp);
                if (Z_TYPE_P(z) != IS_OBJECT) {
                    zend_string *str = _ddtrace_convert_to_string(z);
                    zval_ptr_dtor(z);
                    return str;
                }
            }
            zend_string *class_name = Z_OBJ_HANDLER_P(op, get_class_name)(Z_OBJ_P(op));
            zend_string *message = strpprintf(0, "object(%s)#%d", ZSTR_VAL(class_name), Z_OBJ_HANDLE_P(op));
            zend_string_release(class_name);
            return message;
        }

        case IS_REFERENCE:
            op = Z_REFVAL_P(op);
            goto try_again;

        case IS_STRING:
            return zend_string_copy(Z_STR_P(op));

            EMPTY_SWITCH_DEFAULT_CASE()
    }
}

void ddtrace_convert_to_string(zval *dst, zval *src) {
    zend_string *str = _ddtrace_convert_to_string(src);
    if (str) {
        ZVAL_STR(dst, str);
    } else {
        ZVAL_NULL(dst);
    }
}
