#include <Zend/zend.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_interfaces.h>
#include <php.h>

#include <ext/spl/spl_exceptions.h>

#include "ddtrace.h"
#include "dispatch_compat.h"
#include "mpack/mpack.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace TSRMLS_DC);

#if PHP_VERSION_ID < 70000
static int write_hash_table(mpack_writer_t *writer, HashTable *ht TSRMLS_DC) /* {{{ */
{
    zval **tmp;
    char *string_key;
    uint str_len;
    HashPosition iterator;
    zend_ulong num_key;
    int key_type;
    bool first_time = true;

    zend_hash_internal_pointer_reset_ex(ht, &iterator);
    while (zend_hash_get_current_data_ex(ht, (void **)&tmp, &iterator) == SUCCESS) {
        key_type = zend_hash_get_current_key_ex(ht, &string_key, &str_len, &num_key, 0, &iterator);
        if (first_time == true) {
            first_time = false;
            if (key_type == HASH_KEY_IS_STRING) {
                mpack_start_map(writer, zend_hash_num_elements(ht));
            } else {
                mpack_start_array(writer, zend_hash_num_elements(ht));
            }
        }
        if (key_type == HASH_KEY_IS_STRING) {
            mpack_write_cstr(writer, string_key);
        }
        if (msgpack_write_zval(writer, *tmp TSRMLS_CC) != 1) {
            return 0;
        }
        zend_hash_move_forward_ex(ht, &iterator);
    }

    if (key_type == HASH_KEY_IS_STRING) {
        mpack_finish_map(writer);
    } else {
        mpack_finish_array(writer);
    }
    return 1;
}
#else
static int write_hash_table(mpack_writer_t *writer, HashTable *ht TSRMLS_DC) /* {{{ */
{
    zval *tmp;
    zend_string *string_key;
    int is_assoc = -1;

    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(ht, string_key, tmp) {
        if (is_assoc == -1) {
            is_assoc = string_key != NULL ? 1 : 0;
            if (is_assoc == 1) {
                mpack_start_map(writer, zend_hash_num_elements(ht));
            } else {
                mpack_start_array(writer, zend_hash_num_elements(ht));
            }
        }
        if (is_assoc == 1) {
            mpack_write_cstr(writer, ZSTR_VAL(string_key));
        }
        if (msgpack_write_zval(writer, tmp TSRMLS_CC) != 1) {
            return 0;
        }
    }
    ZEND_HASH_FOREACH_END();

    if (is_assoc) {
        mpack_finish_map(writer);
    } else {
        mpack_finish_array(writer);
    }
    return 1;
}
#endif

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace TSRMLS_DC) /* {{{ */
{
    switch (Z_TYPE_P(trace)) {
        case IS_ARRAY:
            if (write_hash_table(writer, Z_ARRVAL_P(trace) TSRMLS_CC) != 1) {
                return 0;
            }
            break;
        case IS_DOUBLE:
            mpack_write_double(writer, Z_DVAL_P(trace));
            break;
        case IS_LONG:
            mpack_write_int(writer, Z_LVAL_P(trace));
            break;
        case IS_NULL:
            mpack_write_nil(writer);
            break;
#if PHP_VERSION_ID < 70000
        case IS_BOOL:
            mpack_write_bool(writer, Z_BVAL_P(trace) == 1);
            break;
        case IS_STRING:
            mpack_write_cstr(writer, Z_STRVAL_P(trace));
            break;
#else
        case IS_TRUE:
        case IS_FALSE:
            mpack_write_bool(writer, Z_TYPE_P(trace) == IS_TRUE);
            break;
        case IS_STRING:
            mpack_write_cstr(writer, ZSTR_VAL(Z_STR_P(trace)));
            break;
#endif
        default:
            if (DDTRACE_G(strict_mode)) {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0 TSRMLS_CC,
                                        "Serialize values must be of type array, string, int, float, bool or null");
            }
            return 0;
            break;
    }
    return 1;
}

int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p TSRMLS_DC) {
    // encode to memory buffer
    char *data;
    size_t size;
    mpack_writer_t writer;
    mpack_writer_init_growable(&writer, &data, &size);
    if (msgpack_write_zval(&writer, trace TSRMLS_CC) != 1) {
        mpack_writer_destroy(&writer);
        free(data);
        return 0;
    }
    // finish writing
    if (mpack_writer_destroy(&writer) != mpack_ok) {
        free(data);
        return 0;
    }

    if (data_p && size_p) {
        *data_p = data;
        *size_p = size;

        return 1;
    } else {
        return 0;
    }
}

int ddtrace_serialize_simple_array(zval *trace, zval *retval TSRMLS_DC) {
    // encode to memory buffer
    char *data;
    size_t size;

    if (ddtrace_serialize_simple_array_into_c_string(trace, &data, &size TSRMLS_CC)) {
#if PHP_VERSION_ID < 70000
        ZVAL_STRINGL(retval, data, size, 1);
#else
        ZVAL_STRINGL(retval, data, size);
#endif
        free(data);
        return 1;
    } else {
        return 0;
    }
}

#if PHP_VERSION_ID < 70000
static zval *_read_span_property(zval *span_data, const char *name, size_t name_len TSRMLS_DC) {
    return zend_read_property(ddtrace_ce_span_data, span_data, name, name_len, 1 TSRMLS_CC);
}

static void _add_assoc_zval(zval *el, const char *name, zval *prop) {
    zval *value;
    ALLOC_ZVAL(value);
    INIT_PZVAL_COPY(value, prop);
    zval_copy_ctor(value);
    add_assoc_zval(el, name, value);
}

void _serialize_exception(zval *el, zval *meta, ddtrace_span_t *span TSRMLS_DC) {
    zval *exception, *msg, *stack;
    zend_uint class_name_len;
    const char *class_name;
    int dup;
    exception = span->exception;

    add_assoc_long(el, "error", 1);

    dup = zend_get_object_classname(exception, &class_name, &class_name_len TSRMLS_CC);

    zend_call_method_with_0_params(&exception, Z_OBJCE_P(exception), NULL, "getmessage", &msg);
    zend_call_method_with_0_params(&exception, Z_OBJCE_P(exception), NULL, "gettraceasstring", &stack);

    /* add_assoc_stringl does not actually mutate the string, but we've either
     * already made a copy, or it will when it duplicates with dup param, so
     * if it did it should still be safe. */
    add_assoc_stringl(meta, "error.name", (char *)class_name, class_name_len, dup);
    add_assoc_zval(meta, "error.msg", msg);
    add_assoc_zval(meta, "error.stack", stack);
}

void _serialize_meta(zval *el, ddtrace_span_t *span TSRMLS_DC) {
    zval *meta = _read_span_property(span->span_data, "meta", sizeof("meta") - 1 TSRMLS_CC);
    BOOL_T should_free = TRUE;

    if (!meta || Z_TYPE_P(meta) != IS_ARRAY) {
        ALLOC_INIT_ZVAL(meta);
        array_init(meta);
    } else {
        should_free = FALSE;
        Z_ADDREF_P(meta);
    }

    if (span->exception) {
        _serialize_exception(el, meta, span TSRMLS_CC);
    }

    // Add meta only if it has elements
    if (zend_hash_num_elements(Z_ARRVAL_P(meta))) {
        add_assoc_zval(el, "meta", meta);
    } else {
        zval_dtor(meta);

        if (should_free) {
            efree(meta);
        }
    }
}

#else
static zval *_read_span_property(zval *span_data, const char *name, size_t name_len) {
    zval rv;
    return zend_read_property(ddtrace_ce_span_data, span_data, name, name_len, 1, &rv);
}

static void _add_assoc_zval(zval *el, const char *name, zval *prop) {
    zval value;
    ZVAL_COPY(&value, prop);
    add_assoc_zval(el, (name), &value);
}

void _serialize_exception(zval *el, zval *meta, ddtrace_span_t *span) {
    zval exception, name, msg, stack;
    ZVAL_OBJ(&exception, span->exception);

    add_assoc_long(el, "error", 1);

    ZVAL_STR(&name, Z_OBJCE(exception)->name);
    zend_call_method_with_0_params(&exception, Z_OBJCE(exception), NULL, "getmessage", &msg);
    zend_call_method_with_0_params(&exception, Z_OBJCE(exception), NULL, "gettraceasstring", &stack);

    _add_assoc_zval(meta, "error.name", &name);
    add_assoc_zval(meta, "error.msg", &msg);
    add_assoc_zval(meta, "error.stack", &stack);
}

void _serialize_meta(zval *el, ddtrace_span_t *span) {
    zval meta_zv, *meta = _read_span_property(span->span_data, "meta", sizeof("meta") - 1);

    if (!meta || Z_TYPE_P(meta) != IS_ARRAY) {
        meta = &meta_zv;
        array_init(meta);
    } else {
        Z_ADDREF_P(meta);
    }

    if (span->exception) {
        _serialize_exception(el, meta, span);
    }

    if (zend_array_count(Z_ARRVAL_P(meta))) {
        add_assoc_zval(el, "meta", meta);
    } else {
        zval_ptr_dtor(meta);
    }
}
#endif

#define ADD_ELEMENT_IF_PROP_TYPE(name, type)                                                 \
    do {                                                                                     \
        zval *prop = _read_span_property(span->span_data, name, sizeof(name) - 1 TSRMLS_CC); \
        if (Z_TYPE_P(prop) == (type)) {                                                      \
            _add_assoc_zval(el, name, prop);                                                 \
        }                                                                                    \
    } while (0);

void ddtrace_serialize_span_to_array(ddtrace_span_t *span, zval *array TSRMLS_DC) {
    zval *el;
#if PHP_VERSION_ID < 70000
    ALLOC_INIT_ZVAL(el);
#else
    zval zv;
    el = &zv;
#endif
    array_init(el);

    add_assoc_long(el, "trace_id", span->trace_id);
    add_assoc_long(el, "span_id", span->span_id);
    if (span->parent_id > 0) {
        add_assoc_long(el, "parent_id", span->parent_id);
    }
    add_assoc_long(el, "start", span->start);
    add_assoc_long(el, "duration", span->duration);

    ADD_ELEMENT_IF_PROP_TYPE("name", IS_STRING);
    ADD_ELEMENT_IF_PROP_TYPE("resource", IS_STRING);
    ADD_ELEMENT_IF_PROP_TYPE("service", IS_STRING);
    ADD_ELEMENT_IF_PROP_TYPE("type", IS_STRING);

    _serialize_meta(el, span TSRMLS_CC);

    ADD_ELEMENT_IF_PROP_TYPE("metrics", IS_ARRAY);

    add_next_index_zval(array, el);
}
