// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// clang-format off
#include "entity_body.h"
#include "ddappsec.h"
#include "php_compat.h" // NOLINT (must come before entity_body_arginfo.h)
#include "entity_body_arginfo.h"
#include "logging.h"
#include "php_objects.h"
#include "string_helpers.h"
#include "xml_truncated_parser.h"
#include <SAPI.h>
#include <dlfcn.h>
#include <ext/json/php_json.h>
#include <limits.h>
// clang-format on

static typeof(zend_write(NULL, 0)) _dd_save_output_zend_write(
    const char *str, size_t str_length);

#if PHP_VERSION_ID < 70200
typedef void (*json_decode_ex_t)(zval *return_value, char *str, size_t str_len,
    zend_long options, zend_long depth);
#elif PHP_VERSION_ID < 80200
typedef int (*json_decode_ex_t)(zval *return_value, const char *str,
    size_t str_len, zend_long options, zend_long depth);
#else
typedef zend_result (*json_decode_ex_t)(zval *return_value, const char *str,
    size_t str_len, zend_long options, zend_long depth);
#endif
static json_decode_ex_t _json_decode_ex;

// response body buffer
ZEND_TLS zend_string *_buffer;
ZEND_TLS size_t _buffer_size;

static zval _convert_json(char *nonnull entity, size_t entity_len);
static zval _convert_xml(const char *nonnull entity, size_t entity_len,
    const char *nonnull content_type, size_t content_type_len);

#define DEFAULT_MAX_BUF_SIZE (1024 * 512UL)

static typeof(zend_write) orig_zend_write;

void dd_entity_body_startup(void)
{
    orig_zend_write = zend_write;
    zend_write = _dd_save_output_zend_write;

#if PHP_VERSION_ID < 80000
    void *handle = dlopen(NULL, RTLD_NOW | RTLD_GLOBAL);
    if (handle == NULL) {
        // NOLINTNEXTLINE(concurrency-mt-unsafe)
        mlog(dd_log_error, "Failed load process symbols: %s", dlerror());
    } else {
        _json_decode_ex =
            (json_decode_ex_t)(uintptr_t)dlsym(handle, "php_json_decode_ex");
        if (!_json_decode_ex) {
            mlog(dd_log_warning, "Failed to load php_json_decode_ex: %s",
                dlerror()); // NOLINT(concurrency-mt-unsafe)
        }
        dlclose(handle);
    }
#else
    _json_decode_ex = php_json_decode_ex;
#endif

    dd_xml_parser_startup();

    if (get_global_DD_APPSEC_TESTING()) {
        dd_phpobj_reg_funcs(ext_functions);
    }
}

void dd_entity_body_shutdown(void) { dd_xml_parser_shutdown(); }

void dd_entity_body_gshutdown(void)
{
    if (_buffer) {
        zend_string_release(_buffer);
        _buffer = NULL;
    }
}

static typeof(zend_write(NULL, 0)) _dd_save_output_zend_write(
    const char *str, size_t str_length)
{
    if (DDAPPSEC_G(active) && _buffer != NULL) {
        size_t to_write = MIN(str_length, _buffer_size - _buffer->len);
        memcpy(_buffer->val + _buffer->len, str, to_write);
        _buffer->len += to_write;
    }
    return orig_zend_write(str, str_length);
}

void dd_entity_body_rinit(void)
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
        }
        _buffer = zend_string_alloc(desired_bufsize + /* NUL */ 1, 1);
        _buffer_size = desired_bufsize;
    }

    _buffer->len = 0;
}

zend_string *nonnull dd_response_body_buffered(void)
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

zval dd_entity_body_convert(
    const char *nonnull ct, size_t ct_len, zend_string *nonnull entity)
{
    if (ct_len >= LSTRLEN("application/json") &&
        strncasecmp(ct, LSTRARG("application/json")) == 0) {
        return _convert_json(ZSTR_VAL(entity), ZSTR_LEN(entity));
    }
    if ((ct_len >= LSTRLEN("text/xml") &&
            strncasecmp(ct, LSTRARG("text/xml")) == 0) ||
        (ct_len >= LSTRLEN("application/xml") &&
            strncasecmp(ct, LSTRARG("application/xml")) == 0)) {
        return _convert_xml(ZSTR_VAL(entity), ZSTR_LEN(entity), ct, ct_len);
    }
    return (zval){.u1.type_info = IS_NULL};
}

static zval _convert_json(char *nonnull entity, size_t entity_len)
{
    zval zv;
    ZVAL_NULL(&zv);
    if (!_json_decode_ex) {
        return zv;
    }

#define MAX_DEPTH 30
    _json_decode_ex(
        &zv, entity, entity_len, PHP_JSON_OBJECT_AS_ARRAY, MAX_DEPTH);
    if (Z_TYPE(zv) == IS_NULL) {
        mlog(dd_log_info, "Failed to parse JSON response body");
        if (dd_log_level() >= dd_log_trace && entity_len < INT_MAX) {
            mlog(dd_log_trace, "Contents were: %.*s", (int)entity_len, entity);
        }
        zval_ptr_dtor(&zv);
    }
    return zv;
}

static bool _assume_utf8(const char *ct, size_t ct_len)
{
    const char *psemi = memchr(ct, ';', ct_len);
    if (!psemi) {
        return true;
    }
    for (const char *end = ct + ct_len, *c = psemi + 1;
         c < end - LSTRLEN("charset=utf-8") + 1; c++) {
        if (tolower(*c) == 'c' && tolower(*(c + 1)) == 'h' &&
            tolower(*(c + 2)) == 'a' && tolower(*(c + 3)) == 'r' &&
            tolower(*(c + 4)) == 's' && tolower(*(c + 5)) == 'e' && // NOLINT
            tolower(*(c + 6)) == 't') {                             // NOLINT
            c += LSTRLEN("charset");
            for (; c < end && *c == ' '; c++) {}
            if (c < end && *c == '=') {
                for (c++; c < end - LSTRLEN("utf-8") && *c == ' '; c++) {}
                if (tolower(*c) == 'u' && tolower(*(c + 1)) == 't' &&
                    tolower(*(c + 2)) == 'f' && tolower(*(c + 3)) == '-' &&
                    tolower(*(c + 4)) == '8') {
                    return true;
                }
                return false;
            }
            return true;
        }
    }
    return true;
}

#define MAX_XML_DEPTH 30
static zval _convert_xml(const char *nonnull entity, size_t entity_len,
    const char *nonnull content_type, size_t content_type_len)
{
    bool is_utf8 = _assume_utf8(content_type, content_type_len);
    if (!is_utf8) {
        mlog(dd_log_info, "Only UTF-8 is supported for XML parsing");
        return (zval){.u1.type_info = IS_NULL};
    }
    return dd_parse_xml_truncated(entity, entity_len, MAX_XML_DEPTH);
}

PHP_FUNCTION(datadog_appsec_testing_convert_json)
{
    zend_string *entity;
    ZEND_PARSE_PARAMETERS_START(1, 1) // NOLINT
    Z_PARAM_STR(entity)
    ZEND_PARSE_PARAMETERS_END();

    zval result = _convert_json(ZSTR_VAL(entity), ZSTR_LEN(entity));
    RETURN_ZVAL(&result, 0, 0);
}

PHP_FUNCTION(datadog_appsec_testing_convert_xml)
{
    zend_string *entity;
    zend_string *content_type;
    ZEND_PARSE_PARAMETERS_START(2, 2) // NOLINT
    Z_PARAM_STR(entity)
    Z_PARAM_STR(content_type)
    ZEND_PARSE_PARAMETERS_END();

    zval result = _convert_xml(ZSTR_VAL(entity), ZSTR_LEN(entity),
        ZSTR_VAL(content_type), ZSTR_LEN(content_type));

    RETURN_ZVAL(&result, 0, 0);
}
