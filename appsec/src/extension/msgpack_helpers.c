// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// NOLINTNEXTLINE(misc-header-include-cycle)
#include <php.h>

#include "compatibility.h"
#include "logging.h"
#include "msgpack_helpers.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "php_objects.h"

#if !MPACK_HAS_CONFIG
#    error "MPACK_HAS_CONFIG is not defined"
#endif

static const size_t MAX_DEPTH_READING = 32;
#define MAX_RECURSION_DEPTH 50 // arbitrary limit to prevent stack overflow

static THREAD_LOCAL_ON_ZTS bool data_truncated_ = false;

static void _mpack_write_zval(
    mpack_writer_t *nonnull w, zval *nonnull zv, size_t depth);

void dd_mpack_write_nullable_cstr(
    mpack_writer_t *nonnull w, const char *nullable cstr)
{
    if (cstr) {
        mpack_write(w, cstr);
    } else {
        mpack_write_str(w, "", 0);
    }
}

void dd_mpack_write_nullable_cstr_lim(
    mpack_writer_t *nonnull w, const char *nullable cstr, size_t max_len)
{
    if (cstr) {
        size_t len = strlen(cstr);
        if (len > max_len) {
            data_truncated_ = true;
            len = max_len;
        }
        mpack_write_str(w, cstr, len);
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

void dd_mpack_write_nullable_str_lim(mpack_writer_t *nonnull w,
    const char *nullable str, size_t len, size_t max_len)
{
    if (str) {
        if (len > max_len) {
            data_truncated_ = true;
            len = max_len;
        }
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

void dd_mpack_write_zstr_lim(
    mpack_writer_t *nonnull w, const zend_string *nonnull zstr, size_t max_len)
{
    size_t len = ZSTR_LEN(zstr);
    if (len > max_len) {
        data_truncated_ = true;
        len = max_len;
    }
    mpack_write_str(w, ZSTR_VAL(zstr), len);
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

void dd_mpack_write_nullable_zstr_lim(
    mpack_writer_t *nonnull w, const zend_string *nullable zstr, size_t max_len)
{
    if (zstr) {
        dd_mpack_write_zstr_lim(w, zstr, max_len);
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

    _mpack_write_zval(w, zv, 0);
}

// NOLINTNEXTLINE(misc-no-recursion)
static void _dd_mpack_write_array(
    mpack_writer_t *nonnull w, const zend_array *nullable arr, size_t depth)
{
    if (!arr) {
        mpack_write_nil(w);
        return;
    }

    if (depth >= MAX_RECURSION_DEPTH) {
        mlog(dd_log_warning, "Max recursion depth reached, stopping");
        mpack_write_nil(w);
        return;
    }

    uint32_t num_elems = zend_hash_num_elements(arr);
    dd_php_array_type arr_type = dd_php_determine_array_type(arr);
    if (arr_type == php_array_type_sequential) {
        mpack_start_array(w, num_elems);
        zval *val;
        ZEND_HASH_FOREACH_VAL((zend_array *)arr, val)
        {
            _mpack_write_zval(w, val, depth);
        }
        ZEND_HASH_FOREACH_END();
        mpack_finish_array(w);
    } else {
        mpack_start_map(w, num_elems);

        zend_string *key_s;
        zend_ulong key_i;
        zval *val;
        ZEND_HASH_FOREACH_KEY_VAL((zend_array *)arr, key_i, key_s, val)
        {
            if (key_s) {
                dd_mpack_write_zstr(w, key_s);
            } else {
                char buf[ZEND_LTOA_BUF_LEN];
                ZEND_LTOA((zend_long)key_i, buf, sizeof(buf));
                mpack_write(w, buf);
            }
            _mpack_write_zval(w, val, depth);
        }
        ZEND_HASH_FOREACH_END();
        mpack_finish_map(w);
    }
}

void dd_mpack_write_array(
    mpack_writer_t *nonnull w, const zend_array *nullable arr)
{
    _dd_mpack_write_array(w, arr, 0);
}

// NOLINTNEXTLINE(misc-no-recursion)
static void _mpack_write_array_lim(mpack_writer_t *nonnull w,
    const zend_array *nonnull arr, dd_mpack_limits *limits)
{
    if (limits->depth_remaining == 0 || limits->elements_remaining == 0) {
        data_truncated_ = true;
        mpack_write_nil(w);
        return;
    }

    limits->elements_remaining--;
    limits->depth_remaining--;

    uint32_t num_elems = zend_hash_num_elements(arr);
    uint32_t elems_to_write = MIN(num_elems, limits->elements_remaining);

    if (num_elems > elems_to_write) {
        data_truncated_ = true;
    }

    dd_php_array_type arr_type = dd_php_determine_array_type(arr);
    if (arr_type == php_array_type_sequential) {
        // don't take the penalty of dynamically allocating the array
        // i.e. no specification of the array upfront
        // Instead, if we exceed the limit, we'll start writing nil values
        // in place of the actual aray values
        mpack_start_array(w, elems_to_write);
        zval *val;
        ZEND_HASH_FOREACH_VAL((zend_array *)arr, val)
        {
            if (elems_to_write-- == 0) {
                break;
            }
            dd_mpack_write_zval_lim(w, val, limits);
        }
        ZEND_HASH_FOREACH_END();
        mpack_finish_array(w);
    } else {
        mpack_start_map(w, elems_to_write);

        zend_string *key_s;
        zend_ulong key_i;
        zval *val;
        ZEND_HASH_FOREACH_KEY_VAL((zend_array *)arr, key_i, key_s, val)
        {
            if (elems_to_write-- == 0) {
                break;
            }
            if (key_s) {
                dd_mpack_write_zstr_lim(w, key_s, limits->max_string_length);
            } else {
                char buf[ZEND_LTOA_BUF_LEN];
                ZEND_LTOA((zend_long)key_i, buf, sizeof(buf));
                mpack_write(w, buf);
            }
            dd_mpack_write_zval_lim(w, val, limits);
        }
        ZEND_HASH_FOREACH_END();
        mpack_finish_map(w);
    }

    limits->depth_remaining++;
}

void dd_mpack_write_array_lim(mpack_writer_t *nonnull w,
    const zend_array *nullable arr, dd_mpack_limits *nonnull limits)
{
    if (mpack_writer_error(w) != mpack_ok) {
        return;
    }

    if (!arr) {
        mpack_write_nil(w);
        return;
    }

    _mpack_write_array_lim(w, arr, limits);
}

// NOLINTNEXTLINE
static void _mpack_write_zval(
    mpack_writer_t *nonnull w, zval *nonnull zv, size_t depth)
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
        dd_mpack_write_zstr(w, Z_STR_P(zv));
        break;

    case IS_ARRAY: {
        zend_array *arr = Z_ARRVAL_P(zv);
        _dd_mpack_write_array(w, arr, depth + 1);
        break;
    }

    case IS_REFERENCE: {
        zval *referent = Z_REFVAL_P(zv);
        _mpack_write_zval(w, referent, depth);
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

// NOLINTNEXTLINE
static void _mpack_write_zval_lim(
    mpack_writer_t *nonnull w, zval *nonnull zv, dd_mpack_limits *limits)
{
    if (limits->elements_remaining == 0) {
        data_truncated_ = true;
        mpack_write_nil(w);
        return;
    }

    switch (Z_TYPE_P(zv)) {
    case IS_UNDEF:
    case IS_NULL:
        limits->elements_remaining--;
        mpack_write_nil(w);
        break;

    case IS_FALSE:
        limits->elements_remaining--;
        mpack_write(w, false);
        break;

    case IS_TRUE:
        limits->elements_remaining--;
        mpack_write(w, true);
        break;

    case IS_LONG:
        limits->elements_remaining--;
        mpack_write(w, Z_LVAL_P(zv));
        break;

    case IS_DOUBLE:
        limits->elements_remaining--;
        mpack_write(w, Z_DVAL_P(zv));
        break;

    case IS_STRING:
        limits->elements_remaining--;
        dd_mpack_write_zstr_lim(w, Z_STR_P(zv), limits->max_string_length);
        break;

    case IS_ARRAY: {
        // no decrement of elements_remaining here because
        // _mpack_write_array_lim will do it
        zend_array *arr = Z_ARRVAL_P(zv);
        _mpack_write_array_lim(w, arr, limits);
        break;
    }

    case IS_REFERENCE: {
        zval *referent = Z_REFVAL_P(zv);
        _mpack_write_zval_lim(w, referent, limits);
        break;
    }

    default: {
        limits->elements_remaining--;
        mpack_write_nil(w);
        mlog(dd_log_info, "Found unhandled zval type %d. Serialized as nil",
            Z_TYPE_P(zv));
        break;
    }
    }
}

// NOLINTNEXTLINE(misc-no-recursion)
void dd_mpack_write_zval_lim(
    mpack_writer_t *nonnull w, zval *nullable zv, dd_mpack_limits *limits)
{
    if (mpack_writer_error(w) != mpack_ok) {
        return;
    }

    if (!zv) {
        mpack_write_nil(w);
        return;
    }

    _mpack_write_zval_lim(w, zv, limits);
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

void dd_mpack_writer_init_iov(
    mpack_writer_t *nonnull writer, zend_llist *nonnull iovec_list)
{
    MPACK_STATIC_ASSERT(sizeof(iovec_list_t) <= sizeof(writer->reserved),
        "not enough reserved space for growable writer!");
    iovec_list_t *iovecl = (iovec_list_t *)writer->reserved;

    iovecl->list = iovec_list;
    zend_llist_init(iovec_list, sizeof(struct iovec), _iovec_list_destroy, 0);

    const size_t capacity = MPACK_BUFFER_SIZE;
    char *buffer = MPACK_MALLOC(capacity);
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
        zend_llist_add_element(giovec->list, &(struct iovec){
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

// NOLINTNEXTLINE(misc-no-recursion)
static bool parse_element(
    mpack_reader_t *nonnull reader, size_t depth, zval *nonnull output)
{
    if (depth >= MAX_DEPTH_READING) { // critical check!
        mpack_reader_flag_error(reader, mpack_error_too_big);
        mlog(dd_log_error, "decode_msgpack error: msgpack object too big");
        return false;
    }

    mpack_tag_t tag = mpack_read_tag(reader);
    if (mpack_reader_error(reader) != mpack_ok) {
        return false;
    }

    switch (mpack_tag_type(&tag)) {
    case mpack_type_nil:
        ZVAL_NULL(output);
        break;
    case mpack_type_bool:
        ZVAL_BOOL(output, mpack_tag_bool_value(&tag));
        break;
    case mpack_type_int:
        ZVAL_LONG(output, mpack_tag_int_value(&tag));
        break;
    case mpack_type_uint: {
        ZVAL_LONG(output, (long)mpack_tag_uint_value(&tag));
        break;
    }

    case mpack_type_str: {
        uint32_t length = mpack_tag_str_length(&tag);
        const char *data = mpack_read_bytes_inplace(reader, length);
        ZVAL_STRINGL(output, data, length);
        mpack_done_str(reader);
        break;
    }
    case mpack_type_array: {
        uint32_t count = mpack_tag_array_count(&tag);
        array_init(output);
        while (count-- > 0) {
            zval new;
            parse_element(reader, depth + 1, &new);
            if (mpack_reader_error(reader) != mpack_ok) { // critical check!
                //                zval_dtor(&new);
                mlog(
                    dd_log_error, "decode_msgpack error: error decoding array");
                return false;
            }
            zend_hash_next_index_insert(Z_ARRVAL_P(output), &new);
        }
        mpack_done_array(reader);
        break;
    }
    case mpack_type_map: {
        uint32_t count = mpack_tag_map_count(&tag);
        array_init(output);
        while (count-- > 0) {
            zval key;
            zval value;
            if (!parse_element(reader, depth + 1, &key) ||
                Z_TYPE(key) != IS_STRING ||
                !parse_element(reader, depth + 1, &value) ||
                mpack_reader_error(reader) != mpack_ok) { // critical check!
                mlog(dd_log_error, "decode_msgpack error: error decoding map");
                return false;
            }
            // Ignore clang because key is a string here
            // NOLINTNEXTLINE(clang-analyzer-core.CallAndMessage)
            zend_hash_add_new(Z_ARRVAL_P(output), Z_STR(key), &value);
            zval_dtor(&key);
        }
        mpack_done_map(reader);
        break;
    }
    default:
        mlog(dd_log_error, "decode_msgpack error: type %s not implemented.\n",
            mpack_type_to_string(mpack_tag_type(&tag)));
        return false;
    }

    return true;
}

static bool parse_messagepack(
    const char *nonnull data, size_t length, zval *nonnull output)
{
    mpack_reader_t reader;
    mpack_reader_init_data(&reader, data, length);
    parse_element(&reader, 0, output);
    return mpack_ok == mpack_reader_destroy(&reader);
}

static PHP_FUNCTION(datadog_appsec_testing_decode_msgpack)
{
    zend_string *encoded = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S", &encoded) != SUCCESS) {
        RETURN_FALSE;
    }

    parse_messagepack(ZSTR_VAL(encoded), ZSTR_LEN(encoded), return_value);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    void_ret_array_arginfo, 0, 1, IS_ARRAY, 0)
ZEND_ARG_TYPE_INFO(0, encoded, IS_STRING, 0)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "decode_msgpack", PHP_FN(datadog_appsec_testing_decode_msgpack), void_ret_array_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects(void)
{
    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }

    dd_phpobj_reg_funcs(testing_functions);
}

void dd_msgpack_helpers_startup(void) { _register_testing_objects(); }

void dd_msgpack_helpers_rinit(void) { data_truncated_ = false; }

bool dd_msgpack_helpers_is_data_truncated(void) { return data_truncated_; }
