// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <SAPI.h>
#include <ext/standard/url.h>
#include <php.h>

#include "../commands_helpers.h"
#include "../configuration.h"
#include "../ddappsec.h"
#include "../ddtrace.h"
#include "../entity_body.h"
#include "../ip_extraction.h"
#include "../logging.h"
#include "../msgpack_helpers.h"
#include "../php_compat.h"
#include "../string_helpers.h"
#include "request_init.h"
#include <mpack.h>

static dd_result _request_pack(mpack_writer_t *nonnull w, void *nonnull ctx);
static void _init_autoglobals(void);
static void _pack_headers(
    mpack_writer_t *nonnull w, const zend_array *nonnull server);
static void _pack_filenames(
    mpack_writer_t *nonnull w, const zend_array *nonnull files);
static void _pack_files_field_names(
    mpack_writer_t *nonnull w, const zend_array *nonnull files);
static void _pack_path_params(
    mpack_writer_t *nonnull w, const zend_string *nullable uri_raw);
static void _pack_request_body(mpack_writer_t *nonnull w,
    struct req_info_init *nonnull ctx, const zend_array *nonnull server);

static const dd_command_spec _spec = {
    .name = "request_init",
    .name_len = sizeof("request_init") - 1,
    .num_args = 1, // a single map
    .outgoing_cb = _request_pack,
    .incoming_cb = dd_command_proc_resp_verd_span_data,
    .config_features_cb = dd_command_process_config_features,
};

dd_result dd_request_init(
    dd_conn *nonnull conn, struct req_info_init *nonnull ctx)
{
    return dd_command_exec_req_info(conn, &_spec, &ctx->req_info);
}

static dd_result _request_pack(mpack_writer_t *nonnull w, void *nonnull _ctx)
{
    struct req_info_init *nonnull ctx = _ctx;

    bool send_raw_body = get_global_DD_APPSEC_TESTING() &&
                         get_global_DD_APPSEC_TESTING_RAW_BODY();
#define REQUEST_INIT_MAP_NUM_ENTRIES 10
    if (send_raw_body) {
        mpack_start_map(w, REQUEST_INIT_MAP_NUM_ENTRIES + 1);
    } else {
        mpack_start_map(w, REQUEST_INIT_MAP_NUM_ENTRIES);
    }

    // Pack data from SAPI request_info
    sapi_request_info *request_info = &SG(request_info);

    // 1.
    dd_mpack_write_lstr(w, "server.request.query");
    dd_mpack_write_array(w, dd_get_superglob_or_equiv(ZEND_STRL("_GET"),
                                TRACK_VARS_GET, ctx->superglob_equiv));

    // 2.
    const zend_array *nonnull server = dd_get_superglob_or_equiv(
        ZEND_STRL("_SERVER"), TRACK_VARS_SERVER, ctx->superglob_equiv);
    dd_mpack_write_lstr(w, "server.request.method");
    if (ctx->superglob_equiv) {
        dd_mpack_write_nullable_zstr(w,
            dd_php_get_string_elem_cstr(server, ZEND_STRL("REQUEST_METHOD")));
    } else {
        mpack_write(w, request_info->request_method);
    }

    // Pack data from server global
    if (!ctx->superglob_equiv) {
        _init_autoglobals();
    }

    // 3.
    dd_mpack_write_lstr(w, "server.request.cookies");
    dd_mpack_write_array(w, dd_get_superglob_or_equiv(ZEND_STRL("_COOKIE"),
                                TRACK_VARS_COOKIE, ctx->superglob_equiv));

    // 4.
    const zend_string *nullable request_uri =
        dd_php_get_string_elem_cstr(server, ZEND_STRL("REQUEST_URI"));
    dd_mpack_write_lstr(w, "server.request.uri.raw");
    dd_mpack_write_nullable_zstr(w, request_uri);

    // 5.
    dd_mpack_write_lstr(w, "server.request.headers.no_cookies");
    _pack_headers(w, server);

    // 6.
    dd_mpack_write_lstr(w, "server.request.body");
    _pack_request_body(w, ctx, server);

    // 7.
    const zend_array *nonnull files = dd_get_superglob_or_equiv(
        ZEND_STRL("_FILES"), TRACK_VARS_FILES, ctx->superglob_equiv);
    dd_mpack_write_lstr(w, "server.request.body.filenames");
    _pack_filenames(w, files);

    // 8.
    dd_mpack_write_lstr(w, "server.request.body.files_field_names");
    _pack_files_field_names(w, files);

    // 9.
    dd_mpack_write_lstr(w, "server.request.path_params");
    _pack_path_params(w, request_uri);

    // 10.
    dd_mpack_write_lstr(w, "http.client_ip");
    dd_mpack_write_nullable_zstr(w, ctx->req_info.client_ip);

    // 11.
    if (send_raw_body && ctx->entity) {
        dd_mpack_write_lstr(w, "server.request.body.raw");
        dd_mpack_write_zstr(w, ctx->entity);
    }

    mpack_finish_map(w);

    return dd_success;
}

static void _init_autoglobals(void)
{
    // force the autoglobal callback called even if global jit is enabled
    zend_is_auto_global_str(ZEND_STRL("_SERVER"));
    zend_is_auto_global_str(ZEND_STRL("_COOKIE"));
    zend_is_auto_global_str(ZEND_STRL("_POST"));
}

static const char http_prefix[] = "HTTP_";
static const size_t http_prefix_len = sizeof("HTTP_") - 1;
static inline bool _is_relevant_header(const zend_string *key)
{
    return ZSTR_LEN(key) > http_prefix_len &&
           memcmp(ZSTR_VAL(key), http_prefix, http_prefix_len) == 0 &&
           !zend_string_equals_literal(key, "HTTP_COOKIE");
}
static zend_string *_transform_header_name(const zend_string *orig)
{
    size_t header_len = ZSTR_LEN(orig) - http_prefix_len;
    zend_string *ret = zend_string_alloc(header_len, 0);
    char *wp = ZSTR_VAL(ret);
    const char *rp = ZSTR_VAL(orig) + http_prefix_len;
    dd_string_normalize_header2(rp, wp, header_len);
    return ret;
}
static void _pack_headers(
    mpack_writer_t *nonnull w, const zend_array *nonnull server)
{
    mpack_build_map(w);

    // Pack headers
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL((zend_array *)server, key, val)
    {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "CONTENT_TYPE")) {
            dd_mpack_write_lstr(w, "content-type");
            dd_mpack_write_zval(w, val);
        } else if (zend_string_equals_literal(key, "CONTENT_LENGTH")) {
            dd_mpack_write_lstr(w, "content-length");
            dd_mpack_write_zval(w, val);
        } else if (zend_string_equals_literal(key, "PHP_AUTH_DIGEST")) {
            if (Z_TYPE_P(val) == IS_STRING) {
                dd_mpack_write_lstr(w, "authorization");
                size_t auth_len = sizeof("digest ") - 1 + Z_STRLEN_P(val);
                char *auth_str = emalloc(auth_len);
                memcpy(auth_str, "digest ", sizeof("digest ") - 1);
                memcpy(auth_str + sizeof("digest ") - 1, Z_STRVAL_P(val),
                    Z_STRLEN_P(val));
                mpack_write_str(w, auth_str, auth_len);
                efree(auth_str);
            }
        } else if (_is_relevant_header(key)) {
            zend_string *transf_header_name = _transform_header_name(key);
            dd_mpack_write_zstr(w, transf_header_name);
            zend_string_efree(transf_header_name);
            dd_mpack_write_zval(w, val);
        }
    }
    ZEND_HASH_FOREACH_END();

    mpack_complete_map(w);
}

static void _pack_filenames(
    mpack_writer_t *nonnull w, const zend_array *nonnull files)
{
    mpack_build_array(w);

    zval *val;
    ZEND_HASH_FOREACH_VAL((zend_array *)files, val)
    {
        if (!val || Z_TYPE_P(val) != IS_ARRAY) {
            continue;
        }

        zval *fn_zv = zend_hash_str_find(Z_ARRVAL_P(val), ZEND_STRL("name"));
        if (!fn_zv || Z_TYPE_P(fn_zv) != IS_STRING) {
            continue;
        }

        dd_mpack_write_zstr(w, Z_STR_P(fn_zv));
    }
    ZEND_HASH_FOREACH_END();

    mpack_complete_array(w);
}

static void _pack_files_field_names(
    mpack_writer_t *nonnull w, const zend_array *nonnull files)
{
    mpack_build_array(w);

    zend_ulong key_i;
    zend_string *key_s;
    ZEND_HASH_FOREACH_KEY((zend_array *)files, key_i, key_s)
    {
        if (key_s) {
            dd_mpack_write_zstr(w, key_s);
        } else {
            char buf[ZEND_LTOA_BUF_LEN];
            ZEND_LTOA((zend_long)key_i, buf, ZEND_LTOA_BUF_LEN);
            mpack_write(w, buf);
        }
    }
    ZEND_HASH_FOREACH_END();

    mpack_complete_array(w);
}

static void _pack_path_params(
    mpack_writer_t *nonnull w, const zend_string *nullable uri_raw)
{
    if (!uri_raw) {
        mpack_start_array(w, 0);
        mpack_finish_array(w);
        return;
    }

    char *uri_work_zstr = safe_emalloc(ZSTR_LEN(uri_raw), 1, 1);
    memcpy(uri_work_zstr, ZSTR_VAL(uri_raw), ZSTR_LEN(uri_raw) + 1);

    mpack_build_array(w);

    char *p = uri_work_zstr;
    if (*p == '/') {
        // avoid an empty part in the beggining
        p++;
    }
    char *beg = p; // start of current part
    for (;; p++) {
        char c = *p;
        const bool terminates = c == '\0' || c == '?';
        if (c != '/' && !terminates) {
            continue;
        }
        size_t len = p - beg;
        if (len == 0 && terminates) {
            break; // avoid an empty part at the end
        }
        size_t dec_len = php_raw_url_decode(beg, len);
        mpack_write_str(w, beg, dec_len);
        if (terminates) {
            break;
        }
        beg = p + 1;
    }

    efree(uri_work_zstr);
    mpack_complete_array(w);
}

static void _pack_request_body(mpack_writer_t *nonnull w,
    struct req_info_init *nonnull ctx, const zend_array *nonnull server)
{
    const zend_array *post = dd_get_superglob_or_equiv(
        ZEND_STRL("_POST"), TRACK_VARS_POST, ctx->superglob_equiv);
    if (zend_hash_num_elements(post) != 0) {
        dd_mpack_write_array(w, post);
    } else {
        bool written = false;
        if (ctx->entity) {
            zend_string *ct =
                dd_php_get_string_elem_cstr(server, ZEND_STRL("CONTENT_TYPE"));
            if (ct) {
                zval body_zv = dd_entity_body_convert(
                    ZSTR_VAL(ct), ZSTR_LEN(ct), ctx->entity);
                if (Z_TYPE(body_zv) != IS_NULL) {
                    dd_mpack_write_zval(w, &body_zv);
                    zval_ptr_dtor(&body_zv);
                    written = true;
                }
            }
        }
        if (!written) {
            dd_mpack_write_array(w, &zend_empty_array);
        }
    }
}
