// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "entity_body.h"
#include "ddappsec.h"
#include "logging.h"
#include <SAPI.h>
#include <limits.h>

static typeof(zend_write(NULL, 0)) _dd_save_output_zend_write(
    const char *str, size_t str_length);

ZEND_TLS zend_string *_buffer;
ZEND_TLS size_t _buffer_size;

// we need to keep track of all buffers so we can free them on shutdown
// this is in order to avoid having memory leaks reported
static HashTable _all_buffers;

#define DEFAULT_MAX_BUF_SIZE (1024 * 512UL)

static typeof(zend_write) orig_zend_write;

void dd_entity_body_startup()
{
    orig_zend_write = zend_write;
    zend_write = _dd_save_output_zend_write;
    zend_hash_init(&_all_buffers, 0, NULL, NULL, 1);
}

void dd_entity_body_shutdown()
{
    zend_write = orig_zend_write;
    zend_string *key_s = NULL;
    zend_ulong key_i;
    ZEND_HASH_FOREACH_KEY(&_all_buffers, key_i, key_s)
    {
        UNUSED(key_i);
        assert(key_s != NULL);
        zend_string *s;
        memcpy(&s, ZSTR_VAL(key_s), sizeof(s)); // NOLINT
        assert(ZSTR_LEN(key_s) == sizeof(s));   // NOLINT
        zend_string_release(s);
    }
    ZEND_HASH_FOREACH_END();
    zend_hash_destroy(&_all_buffers);
}

static typeof(zend_write(NULL, 0)) _dd_save_output_zend_write(
    const char *str, size_t str_length)
{
    if (DDAPPSEC_G(enabled) && _buffer != NULL) {
        size_t to_write = MIN(str_length, _buffer_size - _buffer->len);
        memcpy(_buffer->val + _buffer->len, str, to_write);
        _buffer->len += to_write;
    }
    return orig_zend_write(str, str_length);
}

void dd_entity_body_activate()
{
    zend_long conf_size = get_DD_APPSEC_MAX_BODY_BUFF_SIZE();
    size_t desired_bufsize;
    if (conf_size <= 0 || conf_size == LONG_MAX) {
        desired_bufsize = DEFAULT_MAX_BUF_SIZE;
    } else {
        desired_bufsize = (size_t)conf_size;
    }

    if (desired_bufsize != _buffer_size) {
        if (_buffer != NULL) {
            zend_string_release(_buffer);
            zend_hash_str_del(
                &_all_buffers, (void *)&_buffer, sizeof(_buffer)); // NOLINT
        }
        _buffer = zend_string_alloc(desired_bufsize + /* NUL */ 1, 1);
        // NOLINTNEXTLINE
        zend_hash_str_add(&_all_buffers, (void *)&_buffer, sizeof(_buffer),
            &(zval){.u1.type_info = IS_NULL}); // value is irrelevant
        _buffer_size = desired_bufsize;
    }

    _buffer->len = 0;
}

zend_string *nonnull dd_response_body_buffered()
{
    // the json decoder is buggy and expects NUL despite being sent the length
    _buffer->val[_buffer->len] = '\0';
    return _buffer;
}

zend_string *nonnull dd_request_body_buffered(size_t limit)
{
    php_stream *stream = SG(request_info).request_body;

    if (!stream) {
        return ZSTR_EMPTY_ALLOC();
    }

    zend_off_t prev_pos = php_stream_tell(stream);

    mlog(dd_log_debug, "Copying request body from stream");
    php_stream_rewind(stream);
    zend_string *body_data =
        php_stream_copy_to_mem(stream, limit, 0 /* not persistent */);

    if (prev_pos != php_stream_tell(stream)) {
        mlog(dd_log_debug, "Restoring stream to position %" PRIi64,
            (int64_t)prev_pos);
        int ret = php_stream_seek(stream, prev_pos, SEEK_SET);
        if (ret == -1) {
            mlog(dd_log_warning, "php_stream_seek failed");
        }
    }

    if (body_data == NULL) {
        mlog(dd_log_info, "Could not read any data from body stream");
        body_data = ZSTR_EMPTY_ALLOC();
    }

    return body_data;
}
