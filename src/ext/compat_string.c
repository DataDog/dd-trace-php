#include "compat_string.h"

#include <Zend/zend_API.h>
#include <php.h>
#include <php_version.h>

#if PHP_VERSION_ID < 70000
int ddtrace_spprintf(char **message, size_t max_len, char *format, ...) {
    va_list arg;
    int len;

    va_start(arg, format);
    len = vspprintf(message, max_len, format, arg);
    va_end(arg);
    return len;
}
#else
size_t ddtrace_spprintf(char **message, size_t max_len, char *format, ...) {
    va_list arg;
    size_t len;

    va_start(arg, format);
    len = vspprintf(message, max_len, format, arg);
    va_end(arg);
    return len;
}
#endif

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

#if PHP_VERSION_ID < 70000
void ddtrace_convert_to_string(zval *dst, zval *src ZEND_FILE_LINE_DC TSRMLS_DC) {
    switch (Z_TYPE_P(src)) {
        case IS_BOOL:
            if (Z_LVAL_P(src)) {
                Z_STRVAL_P(src) = estrndup_rel("1", 1);
                Z_STRLEN_P(src) = 1;
                break;
            }
            /* fall through */

        case IS_NULL:
            Z_STRVAL_P(dst) = STR_EMPTY_ALLOC();
            Z_STRLEN_P(dst) = 0;
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
            Z_STRVAL_P(dst) = estrndup_rel("Array", sizeof("Array") - 1);
            Z_STRLEN_P(dst) = sizeof("Array") - 1;
            break;

        case IS_OBJECT: {
            if (Z_OBJ_HANDLER_P(src, cast_object)) {
                if (Z_OBJ_HANDLER_P(src, cast_object)(src, dst, IS_STRING TSRMLS_CC) == SUCCESS) {
                    return;
                }
            } else if (Z_OBJ_HANDLER_P(src, get)) {
                zval *newop = Z_OBJ_HANDLER_P(src, get)(src TSRMLS_CC);
                if (Z_TYPE_P(newop) != IS_OBJECT) {
                    /* for safety - avoid loop */
                    ddtrace_convert_to_string(dst, newop ZEND_FILE_LINE_CC TSRMLS_CC);

                    // I think?
                    zval_dtor(newop);
                    return;
                }
            }

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
            _zval_copy_ctor_func(dst ZEND_FILE_LINE_CC);
            return;

            EMPTY_SWITCH_DEFAULT_CASE()
    }
    Z_TYPE_P(src) = IS_STRING;
}

#else
/* zend_operators.h wrongfully defines _convert_to_string, so use the ddtrace
 * prefix even though its private to this translation unit */
static zend_string *_ddtrace_convert_to_string(zval *op) {
try_again:
    switch (Z_TYPE_P(op)) {
        case IS_UNDEF:
        case IS_NULL:
        case IS_FALSE:
            return ZSTR_EMPTY_ALLOC();

        case IS_TRUE:
#if PHP_VERSION_ID < 70200
            return CG(one_char_string)['1'] ? CG(one_char_string)['1'] : zend_string_init("1", 1, 0);
#else
            return ZSTR_CHAR('1');
#endif

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
#endif
