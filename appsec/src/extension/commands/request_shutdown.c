// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "request_shutdown.h"
#include "../commands_helpers.h"
#include "../ddappsec.h"
#include "../ddtrace.h"
#include "../entity_body.h"
#include "../logging.h"
#include "../msgpack_helpers.h"
#include "../php_compat.h"
#include "../string_helpers.h"
#include <SAPI.h>

static dd_result _request_pack(mpack_writer_t *nonnull w, void *nonnull ctx);
static void _pack_headers_no_cookies_llist(
    mpack_writer_t *nonnull w, zend_llist *nonnull hl);
static const char *nullable _header_content_type_llist(
    zend_llist *nonnull hl, size_t *nonnull len);
static void _pack_headers_no_cookies_map(
    mpack_writer_t *nonnull w, const zend_array *nonnull headers);
static const char *nullable _header_content_type_zend_array(
    const zend_array *nonnull hl, size_t *nonnull len);

static const dd_command_spec _spec = {
    .name = "request_shutdown",
    .name_len = sizeof("request_shutdown") - 1,
    .num_args =
        4, // a map, api sec sampling key, sidecar queue id, and input_truncated
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
            resp_body = dd_entity_body_convert(ct, ct_len, req_info->entity);
        }
    }

    mpack_start_map(w, 2 + (Z_TYPE(resp_body) != IS_NULL ? 1 : 0));

    // 1.1.
    {
        _Static_assert(sizeof(int) == 4, "expected 32-bit int");
        dd_mpack_write_lstr(w, "server.response.status");
        char buf[sizeof("-2147483648")];
        int size = sprintf(buf, "%d", req_info->status_code);
        mpack_write_str(w, buf, (uint32_t)size);
    }

    // 1.2.
    dd_mpack_write_lstr(w, "server.response.headers.no_cookies");
    if (req_info->resp_headers_fmt == RESP_HEADERS_LLIST) {
        _pack_headers_no_cookies_llist(w, req_info->resp_headers_llist);
    } else {
        _pack_headers_no_cookies_map(w, req_info->resp_headers_arr);
    }

    // 1.3.?
    if (Z_TYPE(resp_body) != IS_NULL) {
        dd_mpack_limits limits = dd_mpack_def_limits;

        dd_mpack_write_lstr(w, "server.response.body");
        dd_mpack_write_zval_lim(w, &resp_body, &limits);
        zval_ptr_dtor_nogc(&resp_body);

        if (dd_mpack_limits_reached(&limits)) {
            mlog(dd_log_info, "Limits reched when serializing response body");
        }
    }

    mpack_finish_map(w);

    // 2.
    mpack_write(w, req_info->api_sec_samp_key);

    // 3.
    mpack_write(w, dd_trace_get_sidecar_queue_id());

    // 4.
    mpack_write_bool(w, dd_msgpack_helpers_is_data_truncated());

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
        dd_mpack_write_zstr(w, key);

        mpack_start_array(w, zend_llist_count(coll));
        zend_llist_position p;
        for (struct _header_val *hv = zend_llist_get_first_ex(coll, &p); hv;
             hv = zend_llist_get_next_ex(coll, &p)) {
            dd_mpack_write_nullable_str_lim(
                w, hv->val, hv->len, DD_MPACK_DEF_STRING_LIMIT);
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

        dd_mpack_write_zstr(w, key);

        if (Z_TYPE_P(val) == IS_ARRAY) {
            mpack_start_array(w, zend_array_count(Z_ARRVAL_P(val)));
            zval *zv;
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(val), zv)
            {
                if (Z_TYPE_P(zv) == IS_STRING) {
                    dd_mpack_write_zstr_lim(
                        w, Z_STR_P(zv), DD_MPACK_DEF_STRING_LIMIT);
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
            dd_mpack_write_zstr_lim(w, Z_STR_P(val), DD_MPACK_DEF_STRING_LIMIT);
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
