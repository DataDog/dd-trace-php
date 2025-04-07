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

    if (get_global_DD_APPSEC_TESTING()) {
        dd_phpobj_reg_funcs(ext_functions);
    }
}

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
    php_json_decode_ex(
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

static zend_array *_transform_attr_keys(const zval *orig)
{
    // append @ to keys
    zend_array *new_arr = zend_new_array(zend_array_count(Z_ARR_P(orig)));
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARR_P(orig), key, val)
    {
        if (!key) {
            // attribute names can't be only numbers anyway
            continue;
        }
        if (Z_TYPE_P(val) != IS_STRING) {
            continue;
        }
        char *new_key = safe_emalloc(ZSTR_LEN(key), 1, 2);
        char *wp = new_key;
        *wp++ = '@';
        memcpy(wp, ZSTR_VAL(key), ZSTR_LEN(key) + 1);
        zval_addref_p(val);
        zend_hash_str_add_new(new_arr, new_key, ZSTR_LEN(key) + 1, val);
        efree(new_key);
    }
    ZEND_HASH_FOREACH_END();
    return new_arr;
}

static zval _convert_xml_impl(const char *nonnull entity, size_t entity_len,
    const char *content_type, size_t content_type_len);
zval _convert_xml(const char *nonnull entity, size_t entity_len,
    const char *nonnull content_type, size_t content_type_len)
{
    if (EG(exception)) {
        return (zval){.u1.type_info = IS_NULL};
    }

    zval ret =
        _convert_xml_impl(entity, entity_len, content_type, content_type_len);
    if (EG(exception)) {
        OBJ_RELEASE(EG(exception));
        EG(exception) = NULL;
    }
    return ret;
}

static bool _assume_utf8(const char *ct, size_t ct_len);
static zval _convert_xml_impl(const char *nonnull entity, size_t entity_len,
    const char *content_type, size_t content_type_len)
{
    static zval null_zv = {.u1.type_info = IS_NULL};
    zval function_name;
    zval parser;
    zval args[4];
    int is_successful;

    /* create XMLParser */
    ZVAL_STRING(&function_name, "xml_parser_create");
    is_successful = call_user_function(
        CG(function_table), NULL, &function_name, &parser, 0, NULL);
    zval_dtor(&function_name);

#if PHP_VERSION_ID >= 80000
#    define XML_PARSER_TYPE IS_OBJECT
#else
#    define XML_PARSER_TYPE IS_RESOURCE
#endif
    if (is_successful == FAILURE || Z_TYPE(parser) != XML_PARSER_TYPE) {
        mlog(dd_log_debug, "Failed to create XML parser");
        if (Z_TYPE(parser) == XML_PARSER_TYPE) {
            zval_dtor(&parser);
        }
        return null_zv;
    }

    /* disable case folding */
    zval retval;
    ZVAL_STRING(&function_name, "xml_parser_set_option");
    ZVAL_COPY_VALUE(&args[0], &parser);
    ZVAL_LONG(&args[1], 1 /*PHP_XML_OPTION_CASE_FOLDING*/);
    ZVAL_BOOL(&args[2], 0);
    is_successful = call_user_function(
        CG(function_table), NULL, &function_name, &retval, 3, args);
    if (is_successful == FAILURE || Z_TYPE_P(&retval) != IS_TRUE) {
        mlog(dd_log_debug, "Failed to set XML parser option");
        zval_dtor(&function_name);
        zval_dtor(&parser);
        return null_zv;
    }

    /* skip whitespace */
    ZVAL_LONG(&args[1], 4 /*PHP_XML_OPTION_SKIP_WHITE*/);
    ZVAL_BOOL(&args[2], 1);
    is_successful = call_user_function(
        CG(function_table), NULL, &function_name, &retval, 3, args);
    zval_dtor(&function_name);
    if (is_successful == FAILURE || Z_TYPE_P(&retval) != IS_TRUE) {
        mlog(dd_log_debug, "Failed to set XML parser option");
        zval_dtor(&parser);
        return null_zv;
    }

    // check if the encoding is UTF-8
    // PHP's xml_parse_into_struct does not support other encodings
    // even after setting the option XML_OPTION_TARGET_ENCODING
    // It never calls xmlSwitchToEncoding()
    bool is_utf8 = _assume_utf8(content_type, content_type_len);
    if (!is_utf8) {
        mlog(dd_log_info, "Only UTF-8 is supported for XML parsing");
        zval_dtor(&parser);
        return null_zv;
    }

    // Call xml_parse_into_struct
    ZVAL_STRING(&function_name, "xml_parse_into_struct");
    ZVAL_STRINGL(&args[1], entity, entity_len);
    ZVAL_NULL(&args[2]);
    ZVAL_MAKE_REF(&args[2]);
    ZVAL_NULL(&args[3]);
    ZVAL_MAKE_REF(&args[3]);
    is_successful = call_user_function(
        CG(function_table), NULL, &function_name, &retval, 4, args);
    zval_dtor(&function_name);
    zval_dtor(&parser); // parser = args[0]
    zval_dtor(&args[1]);
    zval_dtor(&args[3]); // we don't care about the index result
    if (is_successful == FAILURE || Z_TYPE(args[2]) != IS_REFERENCE ||
        Z_TYPE_P(Z_REFVAL(args[2])) != IS_ARRAY || Z_TYPE(retval) != IS_LONG ||
        Z_LVAL(retval) != 1) {
        mlog(dd_log_debug, "Failed to parse XML response body");
        zval_dtor(&args[2]);
        return null_zv;
    }

    // now transform the the result
    // each tag is encoded as a singleton map:
    // <tag name>: [{@attr1: "...", ...}, "text...", {further tags...}])
    // text is encoded as string
    zend_array *root = zend_new_array(1);
    zend_array *cur = root; // non-owning
    zend_array *stack;      // non-owning
    ALLOC_HASHTABLE(stack);
    zend_hash_init(stack, 1, NULL, NULL, 0);

    zval *val_zv;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(Z_REFVAL(args[2])), val_zv)
    {
        if (Z_TYPE_P(val_zv) != IS_ARRAY) {
            continue;
        }
        zend_array *val = Z_ARRVAL_P(val_zv);
        zval *tag_zv = zend_hash_str_find(val, LSTRARG("tag"));
        if (!tag_zv || Z_TYPE_P(tag_zv) != IS_STRING) {
            continue;
        }
        zval *type_zv = zend_hash_str_find(val, LSTRARG("type"));
        if (!type_zv || Z_TYPE_P(type_zv) != IS_STRING) {
            continue;
        }

        enum { open, complete, cdata, close } type;
        if (zend_string_equals_literal(Z_STR_P(type_zv), "open")) {
            type = open;
        } else if (zend_string_equals_literal(Z_STR_P(type_zv), "complete")) {
            type = complete;
        } else if (zend_string_equals_literal(Z_STR_P(type_zv), "cdata")) {
            type = cdata;
        } else if (zend_string_equals_literal(Z_STR_P(type_zv), "close")) {
            type = close;
        } else {
            continue;
        }

        // possible types: open, complete, cdata, close
        if (type == complete || type == open) {
            zval *value_zv = zend_hash_str_find(val, LSTRARG("value"));
            if (value_zv && Z_TYPE_P(value_zv) != IS_STRING) {
                continue;
            }
            zval *attr_zv = zend_hash_str_find(val, LSTRARG("attributes"));
            if (attr_zv && Z_TYPE_P(attr_zv) != IS_ARRAY) {
                continue;
            }

            // add to cur: {<tag>: {content: [(value)], attributes: <attr>]}
            // top singleton map
            zend_array *celem = zend_new_array(1);
            zval celem_zv;
            ZVAL_ARR(&celem_zv, celem);
            zend_hash_next_index_insert(cur, &celem_zv);

            // array with content and attributes
            zend_array *celem_val =
                zend_new_array(attr_zv ? 2 : 1 /* estimate only */);
            {
                zval celem_val_zv;
                ZVAL_ARR(&celem_val_zv, celem_val);
                zend_hash_add_new(celem, Z_STR_P(tag_zv), &celem_val_zv);
            }

            if (attr_zv) {
                zend_array *new_attr = _transform_attr_keys(attr_zv);
                zval new_attr_zv;
                ZVAL_ARR(&new_attr_zv, new_attr);
                zend_hash_next_index_insert(celem_val, &new_attr_zv);
            }

            if (value_zv) {
                zval_addref_p(value_zv);
                zend_hash_next_index_insert(celem_val, value_zv);
            }

            if (type == open) {
                // stash cur, cur = content
                zval cur_zv;
                ZVAL_ARR(&cur_zv, cur);
                zend_hash_next_index_insert(stack, &cur_zv);
                cur = celem_val;
            }
        } else if (type == cdata) {
            zval *value_zv = zend_hash_str_find(val, LSTRARG("value"));
            if (!value_zv || Z_TYPE_P(value_zv) != IS_STRING) {
                continue;
            }

            zval_addref_p(value_zv);
            zend_hash_next_index_insert(cur, value_zv);
        } else { // type == close
            // stash = stash[:-1], cur=stash[-1]
            uint32_t num_elems = zend_hash_num_elements(stack);
            if (num_elems == 0) {
                mlog(dd_log_error, "Invalid XML: too many close tags");
                break;
            }
            zval *cur_zv = zend_hash_index_find(stack, num_elems - 1);
            if (!cur_zv) {
                break;
            }
            zend_hash_index_del(stack, num_elems - 1);
            cur = Z_ARR_P(cur_zv);
        }
    }
    ZEND_HASH_FOREACH_END();

    zval_dtor(&args[2]);

    zend_array_destroy(stack);
    zval *ret_zvp = zend_hash_index_find(root, 0);
    zval ret = null_zv;
    if (ret_zvp) {
        zval_addref_p(ret_zvp);
        ret = *ret_zvp;
    }
    zend_array_destroy(root);

    return ret;
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
