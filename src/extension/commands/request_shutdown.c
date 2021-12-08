// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "request_shutdown.h"
#include "../commands_helpers.h"
#include "../ddappsec.h"
#include "../msgpack_helpers.h"
#include "../string_helpers.h"
#include <SAPI.h>

static dd_result _request_pack(
    mpack_writer_t *nonnull w, void *nullable ATTR_UNUSED ctx);
static void _pack_headers_no_cookies(mpack_writer_t *nonnull w);

static const dd_command_spec _spec = {
    .name = "request_shutdown",
    .name_len = sizeof("request_shutdown") - 1,
    .num_args = 1, // a single map
    .outgoing_cb = _request_pack,
    .incoming_cb = dd_command_proc_resp_verd_span_data,
};

dd_result dd_request_shutdown(dd_conn *nonnull conn)
{
    return dd_command_exec(conn, &_spec, NULL);
}

static dd_result _request_pack(
    mpack_writer_t *nonnull w, void *nullable ATTR_UNUSED ctx)
{
    UNUSED(ctx);

#define REQUEST_SHUTDOWN_MAP_NUM_ENTRIES 2
    mpack_start_map(w, REQUEST_SHUTDOWN_MAP_NUM_ENTRIES);

    // 1.
    {
        _Static_assert(sizeof(int) == 4, "expected 32-bit int");
        dd_mpack_write_lstr(w, "server.response.status");
        int response_code = SG(sapi_headers).http_response_code;
        char buf[sizeof("-2147483648")];
        int size = sprintf(buf, "%d", response_code);
        mpack_write_str(w, buf, (uint32_t)size);
    }

    // 2.
    dd_mpack_write_lstr(w, "server.response.headers.no_cookies");
    _pack_headers_no_cookies(w);

    mpack_finish_map(w);

    return dd_success;
}

static void _dtor_headers_map(zval *zv)
{
    zend_llist *l = Z_PTR_P(zv);
    zend_llist_destroy(l);
    efree(l);
}

static void _pack_headers_no_cookies(mpack_writer_t *nonnull w)
{
    struct _header_val {
        const char *val;
        size_t len;
    };

    zend_llist *hl = &SG(sapi_headers).headers;

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
