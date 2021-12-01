// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include <php.h>

#include "logging.h"
#include "msgpack_helpers.h"
#include "php_helpers.h"

static void _mpack_write_zval(mpack_writer_t *nonnull w, zval *nonnull zv);

void dd_mpack_write_nullable_cstr(
    mpack_writer_t *nonnull w, const char *nullable cstr)
{
    if (cstr) {
        mpack_write(w, cstr);
    } else {
        mpack_write_str(w, "", 0);
    }
}
void dd_mpack_write_nullable_str(
    mpack_writer_t *nonnull w, const char *nullable str, size_t len)
{
    if (str) {
        mpack_write_str(w, str, len);
    } else {
        mpack_write_str(w, "", 0);
    }
}

void dd_mpack_write_zstr(
    mpack_writer_t *nonnull w, const zend_string *nonnull zstr)
{
    mpack_write_str(w, ZSTR_VAL(zstr), ZSTR_LEN(zstr));
}

void dd_mpack_write_nullable_zstr(
    mpack_writer_t *nonnull w, const zend_string *nullable zstr)
{
    if (zstr) {
        dd_mpack_write_zstr(w, zstr);
    } else {
        mpack_write_str(w, "", 0);
    }
}

void dd_mpack_write_zval(mpack_writer_t *nonnull w, zval *nullable zv)
{
    if (mpack_writer_error(w) != mpack_ok) {
        return;
    }
    if (!zv) {
        mpack_write_nil(w);
        return;
    }

    _mpack_write_zval(w, zv);
}

// NOLINTNEXTLINE
static void _mpack_write_zval(mpack_writer_t *nonnull w, zval *nonnull zv)
{

    if (zv == NULL) {
        mpack_write_nil(w);
        return;
    }

    switch (Z_TYPE_P(zv)) {
        case IS_UNDEF:
        case IS_NULL:
        mpack_write_nil(w);
        break;

        case IS_FALSE:
        mpack_write(w, false);
        break;

        case IS_TRUE:
        mpack_write(w, true);
        break;

        case IS_LONG:
        mpack_write(w, Z_LVAL_P(zv));
        break;

        case IS_DOUBLE:
        mpack_write(w, Z_DVAL_P(zv));
        break;

        case IS_STRING:
        mpack_write_str(w, Z_STRVAL_P(zv), Z_STRLEN_P(zv));
        break;

        case IS_ARRAY: {
            zend_array *arr = Z_ARRVAL_P(zv);
            uint32_t num_elems = zend_hash_num_elements(arr);
            dd_php_array_type arr_type = dd_php_determine_array_type(zv);
            if (arr_type == php_array_type_sequential) {
                mpack_start_array(w, num_elems);
                zval *val;
                ZEND_HASH_FOREACH_VAL(arr, val) { _mpack_write_zval(w, val); }
                ZEND_HASH_FOREACH_END();
                mpack_finish_array(w);
            } else {
                mpack_start_map(w, num_elems);

                zend_string *key_s;
                zend_ulong key_i;
                zval *val;
                ZEND_HASH_FOREACH_KEY_VAL(arr, key_i, key_s, val) {
                    if (key_s) {
                        mpack_write_str(w, ZSTR_VAL(key_s), ZSTR_LEN(key_s));
                    } else {
                        char buf[ZEND_LTOA_BUF_LEN];
                        ZEND_LTOA((zend_long)key_i, buf, sizeof(buf));
                        mpack_write(w, buf);
                    }
                    _mpack_write_zval(w, val);
                }
                ZEND_HASH_FOREACH_END();
                mpack_finish_map(w);
            }
            break;
        }

        case IS_REFERENCE: {
            zval *referent = Z_REFVAL_P(zv);
            _mpack_write_zval(w, referent);
            break;
        }

        default: {
            mpack_write_nil(w);
            mlog(dd_log_info, "Found unhandled zval type %d. Serialized as nil",
                Z_TYPE_P(zv));
            break;
        }
    }
}

static void _iovec_writer_flush(
    mpack_writer_t *w, const char *data, size_t count);

static void _iovec_writer_teardown(mpack_writer_t *w);

typedef struct {
    zend_llist *list;
} may_alias iovec_list_t;

static void _iovec_list_destroy(void *ptr)
{
    struct iovec *iov = ptr;
    MPACK_FREE(iov->iov_base);
    iov->iov_base = NULL;
    iov->iov_len = 0;
}

void dd_mpack_writer_init_iov(mpack_writer_t *nonnull writer,
    zend_llist *nonnull iovec_list)
{
    MPACK_STATIC_ASSERT(sizeof(iovec_list_t) <= sizeof(writer->reserved),
        "not enough reserved space for growable writer!");
    iovec_list_t *iovecl = (iovec_list_t *)writer->reserved;

    iovecl->list = iovec_list;
    zend_llist_init(
        iovec_list, sizeof(struct iovec), _iovec_list_destroy, 0);

    const size_t capacity = MPACK_BUFFER_SIZE;
    char* buffer = MPACK_MALLOC(capacity);
    if (buffer == NULL) {
        mpack_writer_init_error(writer, mpack_error_memory);
        return;
    }

    mpack_writer_init(writer, buffer, capacity);
    mpack_writer_set_flush(writer, _iovec_writer_flush);
    mpack_writer_set_teardown(writer, _iovec_writer_teardown);
}

static void _iovec_writer_flush(
    mpack_writer_t *w, const char *data, size_t count)
{
    // There are three ways flush can be called:
    //   - flushing the buffer during writing (used is zero, count is all data,
    //   data is buffer)
    //   - flushing extra data during writing (used is all flushed data, count
    //   is extra data, data is not buffer)
    //   - flushing during teardown (used and count are both all flushed data,
    //   data is buffer)

    iovec_list_t *giovec = (iovec_list_t *)w->reserved;

    if (data == w->buffer) {
        // in this case, we can use the buffer without copying
        zend_llist_add_element(giovec->list, &(struct iovec) {
            .iov_base = w->buffer,
            .iov_len = count,
        });

        if (mpack_writer_buffer_used(w) == count) {
            // teardown, no allocation of new buffer
            w->buffer = NULL;
            w->position = NULL;
            w->end = NULL;
            return;
        }

        char *new_buffer = MPACK_MALLOC(MPACK_BUFFER_SIZE);
        if (!new_buffer) {
            mpack_writer_init_error(w, mpack_error_memory);
            return;
        }
        w->buffer = new_buffer;
        w->position = new_buffer;
        w->end = new_buffer + MPACK_BUFFER_SIZE;
        return;
    }

    // else we need to allocate
    char *iovec_buffer = MPACK_MALLOC(count);
    if (!iovec_buffer) {
        mpack_writer_init_error(w, mpack_error_memory);
        return;
    }

    memcpy(iovec_buffer, data, count); // NOLINT
    zend_llist_add_element(giovec->list, &(struct iovec){
                                             .iov_base = iovec_buffer,
                                             .iov_len = count,
                                         });
}

static void _iovec_writer_teardown(mpack_writer_t *w)
{
    iovec_list_t *giovec = (iovec_list_t *)w->reserved;

    if (mpack_writer_error(w) != mpack_ok) {
        zend_llist_clean(giovec->list);
    }

    MPACK_FREE(w->buffer);
    w->buffer = NULL;
    w->context = NULL;
}
