#include "exceptions.h"

#include <ext/standard/php_smart_str.h>

zai_string_view zai_exception_message(zval *ex TSRMLS_DC) {
    if (!ex) {
        // should never happen; TODO: fail in CI
        return ZAI_STRL_VIEW("(internal error retrieving exception for message)");
    }

    zval *message = ZAI_EXCEPTION_PROPERTY(ex, "message");
    if (Z_TYPE_P(message) != IS_STRING) {
        // interned, so we can forego freeing it ourselves
        return ZAI_STRL_VIEW("(internal error reading exception message)");
    }
    return (zai_string_view){.ptr = Z_STRVAL_P(message), .len = Z_STRLEN_P(message)};
}

/* Modeled after Exception::getTraceAsString:
 * @see https://heap.space/xref/PHP-8.0/Zend/zend_exceptions.c#getTraceAsString
 */
smart_str zai_get_trace_without_args(HashTable *trace) {
    smart_str str = {0};

    if (!trace) {
        // should never happen; TODO: fail in CI
        smart_str_appends(&str, "[broken trace]");
        return str;
    }

    zval **frame;
    int num = 0;
    for (zend_hash_internal_pointer_reset(trace); zend_hash_get_current_data(trace, (void **)&frame) == SUCCESS;
         zend_hash_move_forward(trace)) {
        smart_str_appendc(&str, '#');
        smart_str_append_long(&str, num);
        ++num;
        smart_str_appendc(&str, ' ');

        if (UNEXPECTED(Z_TYPE_PP(frame) != IS_ARRAY)) {
            smart_str_appends(&str, "[invalid frame]\n");
            continue;
        }

        HashTable *ht = Z_ARRVAL_PP(frame);

        zval **file, **tmp;
        if (zend_hash_find(ht, "file", sizeof("file"), (void **)&file) == SUCCESS) {
            if (Z_TYPE_PP(file) != IS_STRING) {
                // before PHP 8.0.7 this was unknown function, but unknown file is much better
                smart_str_appends(&str, "[unknown file]");
            } else {
                long line = 0;
                if (zend_hash_find(ht, "line", sizeof("line"), (void **)&tmp) == SUCCESS && Z_TYPE_PP(tmp) == IS_LONG) {
                    line = Z_LVAL_PP(tmp);
                }
                smart_str_appends(&str, Z_STRVAL_PP(file));
                smart_str_appendc(&str, '(');
                smart_str_append_long(&str, line);
                smart_str_appends(&str, "): ");
            }
        } else {
            smart_str_appends(&str, "[internal function]: ");
        }

        if (zend_hash_find(ht, "class", sizeof("class"), (void **)&tmp) == SUCCESS) {
            smart_str_appends(&str, Z_TYPE_PP(tmp) == IS_STRING ? Z_STRVAL_PP(tmp) : "[unknown]");
        }
        if (zend_hash_find(ht, "type", sizeof("type"), (void **)&tmp) == SUCCESS) {
            smart_str_appends(&str, Z_TYPE_PP(tmp) == IS_STRING ? Z_STRVAL_PP(tmp) : "[unknown]");
        }
        if (zend_hash_find(ht, "function", sizeof("function"), (void **)&tmp) == SUCCESS) {
            smart_str_appends(&str, Z_TYPE_PP(tmp) == IS_STRING ? Z_STRVAL_PP(tmp) : "[unknown]");
        }

        /* We intentionally do not show any arguments, not even an ellipsis if
         * there are arguments. This is because in PHP 7.4+ there is an INI
         * setting called zend.exception_ignore_args that prevents them from
         * being generated, so we can't even reliably know if there are args.
         */
        smart_str_appends(&str, "()\n");
    }

    smart_str_appendc(&str, '#');
    smart_str_append_long(&str, num);
    smart_str_appends(&str, " {main}");
    smart_str_0(&str);

    return str;
}

smart_str zai_get_trace_without_args_from_exception(zval *ex TSRMLS_DC) {
    if (!ex) {
        smart_str s = (smart_str){0};  // should never happen; TODO: fail in CI
        smart_str_appendc(&s, '\0');
        return s;
    }

    zval *trace = ZAI_EXCEPTION_PROPERTY(ex, "trace");
    if (Z_TYPE_P(trace) != IS_ARRAY) {
        // return an empty string here instead of NULL to avoid having to think about handling this
        smart_str s = (smart_str){0};
        smart_str_appendc(&s, '\0');
        return s;
    }

    return zai_get_trace_without_args(Z_ARRVAL_P(trace));
}
