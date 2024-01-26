// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "request_shutdown.h"
#include "../commands_helpers.h"
#include "../ddappsec.h"
#include "../msgpack_helpers.h"
#include "../php_compat.h"
#include "../php_objects.h"
#include "../string_helpers.h"
#include "request_shutdown_arginfo.h"
#include "zend_exceptions.h"
#include <SAPI.h>
#include <ext/json/php_json.h>

static dd_result _request_pack(mpack_writer_t *nonnull w, void *nonnull ctx);
static void _pack_headers_no_cookies_llist(
    mpack_writer_t *nonnull w, zend_llist *nonnull hl);
static const char *nullable _header_content_type_llist(
    zend_llist *nonnull hl, size_t *nonnull len);
static void _pack_headers_no_cookies_map(
    mpack_writer_t *nonnull w, const zend_array *nonnull headers);
static const char *nullable _header_content_type_zend_array(
    const zend_array *nonnull hl, size_t *nonnull len);
static zval _convert_json(char *nonnull entity, size_t entity_len);
static zval _convert_xml(const char *nonnull entity, size_t entity_len,
    const char *content_type, size_t content_type_len);

static const dd_command_spec _spec = {
    .name = "request_shutdown",
    .name_len = sizeof("request_shutdown") - 1,
    .num_args = 1, // a single map
    .outgoing_cb = _request_pack,
    .incoming_cb = dd_command_proc_resp_verd_span_data,
    .config_features_cb = dd_command_process_config_features_unexpected,
};

dd_result dd_request_shutdown(
    dd_conn *nonnull conn, struct req_shutdown_info *nonnull req_info)
{
    return dd_command_exec_req_info(conn, &_spec, &req_info->req_info);
}

static dd_result _request_pack(mpack_writer_t *nonnull w, void *nonnull ctx)
{
    struct req_shutdown_info *nonnull req_info = ctx;

    zval resp_body;
    ZVAL_NULL(&resp_body);
    if (req_info->entity) {
        const char *ct;
        size_t ct_len;
        if (req_info->resp_headers_fmt == RESP_HEADERS_LLIST) {
            ct = _header_content_type_llist(
                req_info->resp_headers_llist, &ct_len);
        } else {
            ct = _header_content_type_zend_array(
                req_info->resp_headers_arr, &ct_len);
        }
        if (ct) {
            if (ct_len >= LSTRLEN("application/json") &&
                strncasecmp(ct, LSTRARG("application/json")) == 0) {
                resp_body = _convert_json(
                    ZSTR_VAL(req_info->entity), ZSTR_LEN(req_info->entity));
            } else if ((ct_len >= LSTRLEN("text/xml") &&
                           strncasecmp(ct, LSTRARG("text/xml")) == 0) ||
                       (ct_len >= LSTRLEN("application/xml") &&
                           strncasecmp(ct, LSTRARG("application/xml")) == 0)) {
                resp_body = _convert_xml(ZSTR_VAL(req_info->entity),
                    ZSTR_LEN(req_info->entity), ct, ct_len);
            }
        }
    }

    mpack_start_map(w, 2 + (Z_TYPE(resp_body) != IS_NULL ? 1 : 0));

    // 1.
    {
        _Static_assert(sizeof(int) == 4, "expected 32-bit int");
        dd_mpack_write_lstr(w, "server.response.status");
        char buf[sizeof("-2147483648")];
        int size = sprintf(buf, "%d", req_info->status_code);
        mpack_write_str(w, buf, (uint32_t)size);
    }

    // 2.
    dd_mpack_write_lstr(w, "server.response.headers.no_cookies");
    if (req_info->resp_headers_fmt == RESP_HEADERS_LLIST) {
        _pack_headers_no_cookies_llist(w, req_info->resp_headers_llist);
    } else {
        _pack_headers_no_cookies_map(w, req_info->resp_headers_arr);
    }

    // 3.?
    if (Z_TYPE(resp_body) != IS_NULL) {
        dd_mpack_write_lstr(w, "server.response.body");
        dd_mpack_write_zval(w, &resp_body);
        zval_ptr_dtor_nogc(&resp_body);
    }

    mpack_finish_map(w);

    return dd_success;
}

static void _dtor_headers_map(zval *zv)
{
    zend_llist *l = Z_PTR_P(zv);
    zend_llist_destroy(l);
    efree(l);
}

static void _pack_headers_no_cookies_llist(
    mpack_writer_t *nonnull w, zend_llist *nonnull hl)
{
    struct _header_val {
        const char *val;
        size_t len;
    };

    // first collect the headers in array of lists
    HashTable headers_map;
    zend_hash_init(
        &headers_map, zend_llist_count(hl), NULL, _dtor_headers_map, 0);

    zend_llist_position pos;
    for (sapi_header_struct *header = zend_llist_get_first_ex(hl, &pos); header;
         header = zend_llist_get_next_ex(hl, &pos)) {
        const char *pcol = memchr(header->header, ':', header->header_len);
        if (!pcol) {
            continue;
        }

        zend_llist *coll; // create or fetch current collection of values
        {
            size_t header_name_len = pcol - header->header;
            zend_string *norm_header_name =
                zend_string_alloc(header_name_len, 0);
            dd_string_normalize_header2(
                header->header, ZSTR_VAL(norm_header_name), header_name_len);

            coll = zend_hash_find_ptr(&headers_map, norm_header_name);
            if (!coll) {
                coll = emalloc(sizeof *coll);
                zend_llist_init(coll, sizeof(struct _header_val), NULL, 0);
                zend_hash_add_new_ptr(&headers_map, norm_header_name, coll);
            }
            zend_string_release(norm_header_name);
        }

        // skip spaces after colon
        const char *const hv_end = header->header + header->header_len;
        const char *hvp;
        for (hvp = pcol + 1; hvp < hv_end && *hvp == ' '; hvp++) {}
        struct _header_val hv = {.val = hvp, .len = hv_end - hvp};
        zend_llist_add_element(coll, &hv);
    }

    // then iterate it add write the msgpack data
    mpack_start_map(w, zend_array_count(&headers_map));
    zend_string *key;
    zend_llist *coll;
    ZEND_HASH_FOREACH_STR_KEY_PTR(&headers_map, key, coll)
    {
        mpack_write_str(w, ZSTR_VAL(key), ZSTR_LEN(key));
        mpack_start_array(w, zend_llist_count(coll));

        zend_llist_position p;
        for (struct _header_val *hv = zend_llist_get_first_ex(coll, &p); hv;
             hv = zend_llist_get_next_ex(coll, &p)) {
            mpack_write_str(w, hv->val, hv->len);
        }
        mpack_finish_array(w);
    }
    ZEND_HASH_FOREACH_END();
    mpack_finish_map(w);

    zend_hash_destroy(&headers_map);
}

static const char *nullable _header_content_type_llist(
    zend_llist *nonnull hl, size_t *nonnull len)
{
    zend_llist_position pos;
    for (sapi_header_struct *header = zend_llist_get_first_ex(hl, &pos); header;
         header = zend_llist_get_next_ex(hl, &pos)) {
        if (header->header_len >= LSTRLEN("content-type") &&
            strncasecmp(header->header, LSTRARG("content-type")) == 0) {
            const char *pcol = memchr(header->header, ':', header->header_len);
            if (!pcol) {
                continue;
            }

            // skip spaces after colon
            const char *const hv_end = header->header + header->header_len;
            const char *start;
            for (start = pcol + 1; start < hv_end && *start == ' '; start++) {}

            *len = header->header + header->header_len - start;
            return start;
        }
    }

    return NULL;
}

static void _pack_headers_no_cookies_map(
    mpack_writer_t *nonnull w, const zend_array *nonnull headers)
{
    mpack_start_map(w, zend_array_count((zend_array *)headers));

    zend_string *key;
    zval *val;
    zend_long idx;
    ZEND_HASH_FOREACH_KEY_VAL((zend_array *)headers, idx, key, val)
    {
        if (!key) {
            mlog(dd_log_warning, "unexpected header array key type: expected a "
                                 "string, not numeric indices");
            key = zend_long_to_str(idx);
        } else {
            zend_string_addref(key);
        }

        mpack_write_str(w, ZSTR_VAL(key), ZSTR_LEN(key));

        if (Z_TYPE_P(val) == IS_ARRAY) {
            mpack_start_array(w, zend_array_count(Z_ARRVAL_P(val)));
            zval *zv;
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(val), zv)
            {
                if (Z_TYPE_P(zv) == IS_STRING) {
                    mpack_write_str(w, Z_STRVAL_P(zv), Z_STRLEN_P(zv));
                } else {
                    mpack_write_str(w, ZEND_STRL("(invalid value)"));
                    mlog(dd_log_warning,
                        "unexpected header value type: %d (value is an array, "
                        "but found an element of it that's no string)",
                        Z_TYPE_P(zv));
                }
            }
            ZEND_HASH_FOREACH_END();
        } else if (Z_TYPE_P(val) == IS_STRING) {
            mpack_start_array(w, 1);
            mpack_write_str(w, Z_STRVAL_P(val), Z_STRLEN_P(val));
        } else {
            mpack_start_array(w, 1);
            mpack_write_str(w, ZEND_STRL("(invalid value)"));
            mlog(dd_log_warning,
                "unexpected header value type: %d (expected string or array of "
                "strings)",
                Z_TYPE_P(val));
        }
        mpack_finish_array(w);

        zend_string_release(key);
    }
    ZEND_HASH_FOREACH_END();
    mpack_finish_map(w);
}

static const char *nullable _header_content_type_zend_array(
    const zend_array *nonnull hl, size_t *nonnull len)
{

    zend_string *key;
    zval *val;
    zend_long idx;
    ZEND_HASH_FOREACH_KEY_VAL((zend_array *)hl, idx, key, val)
    {
        (void)idx;
        if (!key) {
            continue;
        }
        if (zend_string_equals_literal_ci(key, "content-type")) {
            if (Z_TYPE_P(val) == IS_STRING) {
                *len = Z_STRLEN_P(val);
                return Z_STRVAL_P(val);
            }
            if (Z_TYPE_P(val) == IS_ARRAY) {
                zend_array *arr = Z_ARR_P(val);
                HashPosition pos;
                zend_hash_internal_pointer_end_ex(arr, &pos);
                zval *zv = zend_hash_get_current_data_ex(arr, &pos);
                if (!zv) {
                    continue;
                }
                ZVAL_DEREF(zv);
                if (Z_TYPE_P(zv) != IS_STRING) {
                    continue;
                }
                *len = Z_STRLEN_P(zv);
                return Z_STRVAL_P(zv);
            }
        }
    }
    ZEND_HASH_FOREACH_END();

    return NULL;
}

static zval _convert_json(char *nonnull entity, size_t entity_len)
{
    zval zv;
    ZVAL_NULL(&zv);
#define MAX_DEPTH 12
    php_json_decode_ex(
        &zv, entity, entity_len, PHP_JSON_OBJECT_AS_ARRAY, MAX_DEPTH);
    if (Z_TYPE(zv) == IS_NULL) {
        mlog(dd_log_info, "Failed to parse JSON response body");
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
    // <tag name>: {content: [...], attributes: {...})
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

            // map with keys content and attributes
            zend_array *celem_val = zend_new_array(attr_zv ? 2 : 1);
            {
                zval celem_val_zv;
                ZVAL_ARR(&celem_val_zv, celem_val);
                zend_hash_add_new(celem, Z_STR_P(tag_zv), &celem_val_zv);
            }

            zend_array *content = NULL;
            if (type == open || value_zv) {
                content = zend_new_array(1);
                {
                    zval content_zv;
                    ZVAL_ARR(&content_zv, content);
                    zend_hash_str_add_new(celem_val, "content",
                        sizeof("content") - 1, &content_zv);
                }
                if (value_zv) {
                    zval_addref_p(value_zv);
                    zend_hash_next_index_insert(content, value_zv);
                }
            }

            if (attr_zv) {
                zval_addref_p(attr_zv);
                zend_hash_str_add_new(
                    celem_val, "attributes", sizeof("attributes") - 1, attr_zv);
            }

            if (type == open) {
                // stash cur, cur = content
                zval cur_zv;
                ZVAL_ARR(&cur_zv, cur);
                zend_hash_next_index_insert(stack, &cur_zv);
                assert(content != NULL);
                cur = content;
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

static zval _convert_xml(const char *nonnull entity, size_t entity_len,
    const char *content_type, size_t content_type_len)
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

void dd_request_shutdown_startup()
{
    if (get_global_DD_APPSEC_TESTING()) {
        dd_phpobj_reg_funcs(ext_functions);
    }
}
