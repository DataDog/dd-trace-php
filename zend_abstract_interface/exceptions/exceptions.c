#include "../tsrmls_cache.h"
#include "exceptions.h"

#include <Zend/zend_smart_str.h>

#if PHP_VERSION_ID < 70200
#define zend_string_init_interned(...) zend_new_interned_string(zend_string_init(__VA_ARGS__))
#endif

#if PHP_VERSION_ID < 70300
#define zend_hash_find_ex(ht, name, known) zend_hash_find(ht, name)
#endif

#if PHP_VERSION_ID < 70100
#define ZEND_STR_FILE "file"
#define ZEND_STR_LINE "line"
#define ZEND_STR_CLASS "class"
#define ZEND_STR_TYPE "type"
#define ZEND_STR_FUNCTION "function"
#define ZEND_STR_TRACE "trace"
#define ZEND_STR_MESSAGE "message"

#define zend_hash_find(ht, name) zend_hash_str_find(ht, ZEND_STRL(name))
#define ZSTR_KNOWN(id) id
#elif PHP_VERSION_ID < 70200
#define ZSTR_KNOWN(id) CG(known_strings)[id]
#endif

zend_string *zai_exception_message(zend_object *ex) {
    if (!ex || !instanceof_function(ex->ce, zend_ce_throwable)) {
        // should never happen; TODO: fail in CI
        return zend_string_init_interned(ZEND_STRL("(internal error retrieving exception for message)"), 1);
    }

    zval *message = ZAI_EXCEPTION_PROPERTY(ex, ZEND_STR_MESSAGE);
    if (Z_TYPE_P(message) != IS_STRING) {
        // interned, so we can forego freeing it ourselves
        return zend_string_init_interned(ZEND_STRL("(internal error reading exception message)"), 1);
    }
    return Z_STR_P(message);
}

/* Modeled after Exception::getTraceAsString:
 * @see https://heap.space/xref/PHP-8.0/Zend/zend_exceptions.c#getTraceAsString
 */
zend_string *zai_get_trace_without_args_skip_frames(zend_array *trace, int skip) {
    if (!trace) {
        // should never happen; TODO: fail in CI
        return zend_string_init_interned(ZEND_STRL("[broken trace]"), 1);
    }

    zval *frame;
    smart_str str = {0};
    uint32_t num = 0;
    ZEND_HASH_FOREACH_VAL(trace, frame) {
        if (skip-- > 0) {
            continue;
        }

        smart_str_appendc(&str, '#');
        smart_str_append_long(&str, num++);
        smart_str_appendc(&str, ' ');

        if (UNEXPECTED(Z_TYPE_P(frame) != IS_ARRAY)) {
            smart_str_appends(&str, "[invalid frame]\n");
            continue;
        }

        zend_array *ht = Z_ARRVAL_P(frame);

        zval *file = zend_hash_find_ex(ht, ZSTR_KNOWN(ZEND_STR_FILE), 1);
        if (file) {
            if (Z_TYPE_P(file) != IS_STRING) {
                // before PHP 8.0.7 this was unknown function, but unknown file is much better
                smart_str_appends(&str, "[unknown file]");
            } else {
                zend_long line = 0;
                zval *tmp = zend_hash_find_ex(ht, ZSTR_KNOWN(ZEND_STR_LINE), 1);
                if (tmp && Z_TYPE_P(tmp) == IS_LONG) {
                    line = Z_LVAL_P(tmp);
                }
                smart_str_append(&str, Z_STR_P(file));
                smart_str_appendc(&str, '(');
                smart_str_append_long(&str, line);
                smart_str_appends(&str, "): ");
            }
        } else {
            smart_str_appends(&str, "[internal function]: ");
        }

        {
            zval *tmp = zend_hash_find_ex(ht, ZSTR_KNOWN(ZEND_STR_CLASS), 1);
            if (tmp) {
                smart_str_appends(&str, Z_TYPE_P(tmp) == IS_STRING ? Z_STRVAL_P(tmp) : "[unknown]");
            }
        }
        {
            zval *tmp = zend_hash_find_ex(ht, ZSTR_KNOWN(ZEND_STR_TYPE), 1);
            if (tmp) {
                smart_str_appends(&str, Z_TYPE_P(tmp) == IS_STRING ? Z_STRVAL_P(tmp) : "[unknown]");
            }
        }
        {
            zval *tmp = zend_hash_find_ex(ht, ZSTR_KNOWN(ZEND_STR_FUNCTION), 1);
            if (tmp) {
                smart_str_appends(&str, Z_TYPE_P(tmp) == IS_STRING ? Z_STRVAL_P(tmp) : "[unknown]");
            }
        }

        /* We intentionally do not show any arguments, not even an ellipsis if
         * there are arguments. This is because in PHP 7.4+ there is an INI
         * setting called zend.exception_ignore_args that prevents them from
         * being generated, so we can't even reliably know if there are args.
         */
        smart_str_appends(&str, "()\n");
    }
    ZEND_HASH_FOREACH_END();

    smart_str_appendc(&str, '#');
    smart_str_append_long(&str, num);
    smart_str_appends(&str, " {main}");
    smart_str_0(&str);

    return str.s;
}

zend_string *zai_get_trace_without_args(zend_array *trace) {
    return zai_get_trace_without_args_skip_frames(trace, 0);
}

zend_string *zai_get_trace_without_args_from_exception_skip_frames(zend_object *ex, int skip) {
    if (!ex) {
        return ZSTR_EMPTY_ALLOC();  // should never happen; TODO: fail in CI
    }

    zval *trace = ZAI_EXCEPTION_PROPERTY(ex, ZEND_STR_TRACE);
    if (Z_TYPE_P(trace) != IS_ARRAY) {
        // return an empty string here instead of NULL to avoid having to think about handling this
        return ZSTR_EMPTY_ALLOC();  // should never happen in PHP 8 as the property is typed and always initialized
    }

    return zai_get_trace_without_args_skip_frames(Z_ARR_P(trace), skip);
}

zend_string *zai_get_trace_without_args_from_exception(zend_object *ex) {
    return zai_get_trace_without_args_from_exception_skip_frames(ex, 0);
}