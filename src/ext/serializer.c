#include <php.h>
#include <stdio.h>
#include "mpack/mpack.h"

static void ddtrace_zval_to_writer(mpack_writer_t *writer, zval *trace);

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
                mpack_start_map(writer, ht->nNumOfElements);
            } else {
                mpack_start_array(writer, ht->nNumOfElements);
            }
        }
        if (is_assoc == 1) {
            mpack_write_cstr(writer, ZSTR_VAL(string_key));
        }
        ddtrace_zval_to_writer(writer, tmp);
    } ZEND_HASH_FOREACH_END();

    if (is_assoc) {
        mpack_finish_map(writer);
    } else {
        mpack_finish_array(writer);
    }
}

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
        case IS_TRUE:
        case IS_FALSE:
            mpack_write_bool(writer, Z_TYPE_P(trace) == IS_TRUE);
            break;
        case IS_STRING:
            mpack_write_cstr(writer, ZSTR_VAL(Z_STR_P(trace)));
            break;
        default:
            {
                // @TODO Error message
                mpack_write_cstr(writer, "unknown type");
            }
            break;
    }
}

int ddtrace_serialize_trace(zval *trace, zval *retval) {
    // encode to memory buffer
    char* data;
    size_t size;
    mpack_writer_t writer;
    mpack_writer_init_growable(&writer, &data, &size);
    ddtrace_zval_to_writer(&writer, trace);
    // finish writing
    if (mpack_writer_destroy(&writer) != mpack_ok) {
        return 0;
    }
    ZVAL_STRINGL(retval, data, size);
    free(data);
    return 1;
}
