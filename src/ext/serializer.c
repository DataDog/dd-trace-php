#include <php.h>
#include <stdio.h>
#include "mpack/mpack.h"

static void ddtrace_zval_to_writer(mpack_writer_t *writer, zval *trace);

#if PHP_VERSION_ID < 70000
static void ddtrace_hash_table_to_writer(mpack_writer_t *writer, HashTable *ht) /* {{{ */
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
        ddtrace_zval_to_writer(writer, *tmp);
        zend_hash_move_forward_ex(ht, &iterator);
    }

    if (key_type == HASH_KEY_IS_STRING) {
        mpack_finish_map(writer);
    } else {
        mpack_finish_array(writer);
    }
}
#else
static void ddtrace_hash_table_to_writer(mpack_writer_t *writer, HashTable *ht) /* {{{ */
{
    zval *tmp;
    zend_string *string_key;
    zend_ulong num_key;
    int is_assoc = -1;

    ZEND_HASH_FOREACH_KEY_VAL_IND(ht, num_key, string_key, tmp) {
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
        ddtrace_zval_to_writer(writer, tmp);
    }
    ZEND_HASH_FOREACH_END();

    if (is_assoc) {
        mpack_finish_map(writer);
    } else {
        mpack_finish_array(writer);
    }
}
#endif

static void ddtrace_zval_to_writer(mpack_writer_t *writer, zval *trace) /* {{{ */
{
    switch (Z_TYPE_P(trace)) {
        case IS_ARRAY:
            ddtrace_hash_table_to_writer(writer, Z_ARRVAL_P(trace));
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
        default: {
            // @TODO Error message
            mpack_write_cstr(writer, "unknown type");
        } break;
    }
}

int ddtrace_serialize_trace(zval *trace, zval *retval) {
    // encode to memory buffer
    char *data;
    size_t size;
    mpack_writer_t writer;
    mpack_writer_init_growable(&writer, &data, &size);
    ddtrace_zval_to_writer(&writer, trace);
    // finish writing
    if (mpack_writer_destroy(&writer) != mpack_ok) {
        return 0;
    }
#if PHP_VERSION_ID < 70000
    ZVAL_STRINGL(retval, data, size, 1);
#else
    ZVAL_STRINGL(retval, data, size);
#endif
    free(data);
    return 1;
}
