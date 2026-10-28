// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// clang-format off
#include "entity_body.h"
#include "body_parser.h"
#include "ddappsec.h"
#include "dddefs.h"
#include <ext/compatibility.h> // NOLINT (must come before entity_body_arginfo.h)
#ifdef TESTING
#include "entity_body_arginfo.h"
#endif
#include "logging.h"
#include "php_objects.h"
#include "string_helpers.h"
#include <SAPI.h>
#include <ctype.h>
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

static ddog_AppsecBodyMessagePack _convert_json(
    const char *nonnull entity, size_t entity_len);
static ddog_AppsecBodyMessagePack _convert_xml(const char *nonnull entity,
    size_t entity_len, const char *nonnull content_type,
    size_t content_type_len);
static bool _charset_is_utf8_compatible(const char *value, const char *end);
static bool _charset_equals(const char *value, const char *end,
    const char *nonnull name, size_t name_len);

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

#ifdef TESTING
    if (get_global_DD_APPSEC_TESTING()) {
        dd_phpobj_reg_funcs(ext_functions);
    }
#endif
}

void dd_entity_body_gshutdown(ddappsec_globals *nonnull globals)
{
    if (globals->resp_body_buf) {
        zend_string_release(globals->resp_body_buf);
        globals->resp_body_buf = NULL;
        globals->resp_body_buf_size = 0;
    }
}

static typeof(zend_write(NULL, 0)) _dd_save_output_zend_write(
    const char *str, size_t str_length)
{
    if (DDAPPSEC_G(active) && DDAPPSEC_G(resp_body_buf) != NULL) {
        size_t to_write = MIN(str_length,
            DDAPPSEC_G(resp_body_buf_size) - DDAPPSEC_G(resp_body_buf)->len);
        memcpy(DDAPPSEC_G(resp_body_buf)->val + DDAPPSEC_G(resp_body_buf)->len,
            str, to_write);
        DDAPPSEC_G(resp_body_buf)->len += to_write;
    }
    return orig_zend_write(str, str_length);
}

// Maximum response body buffer when raw body sending is enabled. Two bodies
// (parsed + raw) must fit within the helper protocol limit (10 MiB) with room
// for headers and other fields.
#define MAX_BODY_BUFF_RAW_ENABLED (4UL * 1024UL * 1024UL)

void dd_entity_body_rinit(void)
{
    zend_long conf_size = get_DD_APPSEC_MAX_BODY_BUFF_SIZE();
    size_t desired_bufsize;
    if (conf_size <= 0 || conf_size == LONG_MAX) {
        desired_bufsize = DEFAULT_MAX_BUF_SIZE;
    } else {
        desired_bufsize = (size_t)conf_size;
    }

    size_t uncapped_bufsize = desired_bufsize;
    if (get_global_DD_APPSEC_RAW_RESPONSE_BODY_ENABLED() &&
        desired_bufsize > MAX_BODY_BUFF_RAW_ENABLED) {
        desired_bufsize = MAX_BODY_BUFF_RAW_ENABLED;
    }

    if (desired_bufsize != DDAPPSEC_G(resp_body_buf_size)) {
        if (uncapped_bufsize != desired_bufsize) {
            mlog(dd_log_warning,
                "DD_APPSEC_MAX_BODY_BUFF_SIZE (%zu) exceeds maximum allowed "
                "when DD_APPSEC_RAW_RESPONSE_BODY_ENABLED is set (%zu); "
                "capping",
                uncapped_bufsize, (size_t)MAX_BODY_BUFF_RAW_ENABLED);
        }
        if (DDAPPSEC_G(resp_body_buf) != NULL) {
            zend_string_release(DDAPPSEC_G(resp_body_buf));
        }
        DDAPPSEC_G(resp_body_buf) =
            zend_string_alloc(desired_bufsize + /* NUL */ 1, 1);
        DDAPPSEC_G(resp_body_buf_size) = desired_bufsize;
    }

    DDAPPSEC_G(resp_body_buf)->len = 0;
}

zend_string *nonnull dd_response_body_buffered(void)
{
    // the json decoder is buggy and expects NUL despite being sent the length
    DDAPPSEC_G(resp_body_buf)->val[DDAPPSEC_G(resp_body_buf)->len] = '\0';
    return DDAPPSEC_G(resp_body_buf);
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

ddog_AppsecBodyMessagePack dd_entity_body_convert(
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
    return (ddog_AppsecBodyMessagePack){
        .status = DDOG_APPSEC_BODY_STATUS_EMPTY};
}

static ddog_AppsecBodyMessagePack _convert_json(
    const char *nonnull entity, size_t entity_len)
{
#define MAX_DEPTH 30
    return dd_parse_json_truncated(entity, entity_len, MAX_DEPTH);
}

static bool _assume_utf8(const char *ct, size_t ct_len)
{
    const char *psemi = memchr(ct, ';', ct_len);
    if (!psemi) {
        return true;
    }
    for (const char *end = ct + ct_len, *c = psemi + 1; c < end;) {
        const char *name = c;
        bool quoted = false;
        // Only semicolons outside quoted strings separate parameters.
        // Respect quoted-pairs so escaped quotes do not end the string.
        while (c < end) {
            if (quoted && *c == '\\' && c + 1 < end) {
                c += 2;
                continue;
            }
            if (*c == '"') {
                quoted = !quoted;
            } else if (*c == ';' && !quoted) {
                break;
            }
            c++;
        }
        const char *param_end = c;
        if (c < end) {
            c++;
        }
        const char *equal = memchr(name, '=', (size_t)(param_end - name));
        if (!equal) {
            continue;
        }
        const char *name_end = equal;
        while (name < name_end && (*name == ' ' || *name == '\t')) { name++; }
        while (
            name_end > name && (name_end[-1] == ' ' || name_end[-1] == '\t')) {
            name_end--;
        }
        if ((size_t)(name_end - name) == LSTRLEN("charset") &&
            strncasecmp(name, LSTRARG("charset")) == 0) {
            return _charset_is_utf8_compatible(equal + 1, param_end);
        }
    }
    return true;
}

static bool _charset_is_utf8_compatible(const char *value, const char *end)
{
    static const struct {
        const char *name;
        size_t len;
    } charsets[] = {
        {LSTRARG("utf-8")},
        // US-ASCII text is also valid UTF-8 with the same meaning. The XML
        // declaration, not this header, then decides how it is decoded.
        {LSTRARG("us-ascii")},
    };
    for (size_t i = 0; i < ARRAY_SIZE(charsets); i++) {
        if (_charset_equals(value, end, charsets[i].name, charsets[i].len)) {
            return true;
        }
    }
    return false;
}

static bool _charset_equals(const char *value, const char *end,
    const char *nonnull name, size_t name_len)
{
    while (value < end && (*value == ' ' || *value == '\t')) { value++; }
    while (end > value && (end[-1] == ' ' || end[-1] == '\t')) { end--; }
    bool quoted = value < end && *value == '"';
    if (quoted) {
        if (end - value < 2 || end[-1] != '"') {
            return false;
        }
        value++;
        end--;
    }
    for (size_t i = 0; i < name_len; i++) {
        if (value == end) {
            return false;
        }
        unsigned char ch = (unsigned char)*value++;
        if (quoted && ch == '\\') {
            if (value == end) {
                return false;
            }
            ch = (unsigned char)*value++;
        }
        if (tolower(ch) != name[i]) {
            return false;
        }
    }
    return value == end;
}

enum { MAX_XML_DEPTH = 30 };
static ddog_AppsecBodyMessagePack _convert_xml(const char *nonnull entity,
    size_t entity_len, const char *nonnull content_type,
    size_t content_type_len)
{
    bool is_utf8 = _assume_utf8(content_type, content_type_len);
    if (!is_utf8) {
        mlog(dd_log_info,
            "Only UTF-8 and US-ASCII are supported for XML parsing");
        return (ddog_AppsecBodyMessagePack){
            .status = DDOG_APPSEC_BODY_STATUS_EMPTY};
    }
    return dd_parse_xml_truncated(entity, entity_len, MAX_XML_DEPTH);
}

#ifdef TESTING
PHP_FUNCTION(datadog_appsec_convert_xml)
{
    zend_string *entity;
    zend_string *content_type;
    ZEND_PARSE_PARAMETERS_START(2, 2) // NOLINT
    Z_PARAM_STR(entity)
    Z_PARAM_STR(content_type)
    ZEND_PARSE_PARAMETERS_END();

    ddog_AppsecBodyMessagePack body = _convert_xml(ZSTR_VAL(entity),
        ZSTR_LEN(entity), ZSTR_VAL(content_type), ZSTR_LEN(content_type));
    dd_body_msgpack_to_zval(body, return_value);
}

PHP_FUNCTION(datadog_appsec_convert_json)
{
    zend_string *entity;
    enum { MAX_DEPTH_DEFAULT = 30 };
    zend_long max_depth = MAX_DEPTH_DEFAULT;
    ZEND_PARSE_PARAMETERS_START(1, 2) // NOLINT
    Z_PARAM_STR(entity)
    Z_PARAM_OPTIONAL
    Z_PARAM_LONG(max_depth)
    ZEND_PARSE_PARAMETERS_END();

    ddog_AppsecBodyMessagePack body = dd_parse_json_truncated(
        ZSTR_VAL(entity), ZSTR_LEN(entity), (int)max_depth);
    dd_body_msgpack_to_zval(body, return_value);
}
#endif
