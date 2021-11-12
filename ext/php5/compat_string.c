#include "compat_string.h"

#include <Zend/zend_API.h>
#include <php.h>
#include <php_version.h>

#include "compatibility.h"

int ddtrace_spprintf(char **message, size_t max_len, char *format, ...) {
    va_list arg;
    int len;

    va_start(arg, format);
    len = vspprintf(message, max_len, format, arg);
    va_end(arg);
    return len;
}

void ddtrace_downcase_zval(zval *src) {
    if (!src || Z_TYPE_P(src) != IS_STRING) {
        return;
    }

    zend_str_tolower(Z_STRVAL_P(src), Z_STRLEN_P(src));
}

void ddtrace_convert_to_string(zval *dst, zval *src TSRMLS_DC) {
    switch (Z_TYPE_P(src)) {
        case IS_BOOL:
            if (Z_LVAL_P(src)) {
                ZVAL_STRING(dst, "(true)", 1);
            } else {
                ZVAL_STRING(dst, "(false)", 1);
            }
            break;

        case IS_NULL:
            ZVAL_STRING(dst, "(null)", 1);
            break;

        case IS_RESOURCE:
            Z_STRLEN_P(dst) = ddtrace_spprintf(&Z_STRVAL_P(dst), 0, "Resource id #%ld", Z_LVAL_P(src));
            break;

        case IS_LONG:
            Z_STRLEN_P(dst) = ddtrace_spprintf(&Z_STRVAL_P(dst), 0, "%ld", Z_LVAL_P(src));
            break;

        case IS_DOUBLE:
            Z_STRLEN_P(dst) = ddtrace_spprintf(&Z_STRVAL_P(dst), 0, "%.*G", (int)EG(precision), Z_DVAL_P(src));
            break;

        case IS_ARRAY:
            ZVAL_STRING(dst, "Array", 1);
            break;

        case IS_OBJECT: {
            char *class_name;
            zend_uint class_name_len;
            Z_OBJ_HANDLER_P(src, get_class_name)(src, (const char **)&class_name, &class_name_len, 0 TSRMLS_CC);
            Z_STRLEN_P(dst) = ddtrace_spprintf(&Z_STRVAL_P(dst), 0, "object(%s)#%d", class_name, Z_OBJ_HANDLE_P(src));
            efree(class_name);
            break;
        }

        case IS_CONSTANT:
        case IS_STRING:
            ZVAL_COPY_VALUE(dst, src);
            zval_copy_ctor(dst);
            return;

            EMPTY_SWITCH_DEFAULT_CASE()
    }
    Z_TYPE_P(dst) = IS_STRING;
}
