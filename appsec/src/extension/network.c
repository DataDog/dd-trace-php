// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// NOLINTNEXTLINE(misc-header-include-cycle)
#include <php.h>

#include <stdint.h>
#include <string.h>

#define HELPER_PROCESS_C_INCLUDES
#include "ddappsec.h"
#include "dddefs.h"
#include "ddtrace.h"
#include "logging.h"
#include "network.h"
#include "php_compat.h"

struct PACKED _dd_header { // NOLINT
    char code[4];          // dds\0
    uint32_t size;
};

typedef struct PACKED _dd_header dd_header;

_Static_assert(sizeof(dd_header) == DD_CONN_REQUEST_HEADER_SIZE,
    "unexpected AppSec request header size");

static const uint32_t MAX_RECV_MESSAGE_SIZE = 4 * 1024 * 1024;

void dd_conn_init(dd_conn *nonnull conn)
{
    conn->connected = true;
    conn->client_id = 0;
}

dd_result dd_conn_roundtrip(dd_conn *nonnull conn, char *nonnull request,
    size_t request_len, dd_helper_response *nonnull response_out)
{
    if (conn == NULL) {
        return dd_error;
    }
    *response_out = (dd_helper_response){0};

    if (!dd_conn_connected(conn)) {
        return dd_error;
    }

    if (request_len < sizeof(dd_header)) {
        return dd_error;
    }

    size_t data_len_out = request_len - sizeof(dd_header);
    if (data_len_out > UINT32_MAX) {
        mlog(dd_log_warning, "Outgoing appsec message too large: %zu",
            data_len_out);
        return dd_helper_say_goobye;
    }

    dd_header out_h = {"dds", (uint32_t)data_len_out};
    memcpy(request, &out_h, sizeof(out_h));

#ifdef ZTS
    ddog_AppsecCResponse response =
        dd_trace_send_appsec_message(conn->client_id, DDAPPSEC_G(ts_ls_cache),
            (const uint8_t *)request, request_len);
#else
    ddog_AppsecCResponse response = dd_trace_send_appsec_message(
        conn->client_id, (const uint8_t *)request, request_len);
#endif

    dd_result ret;

    if (response.disconnect) {
        mlog(dd_log_warning,
            "Helper has responded with an error indicating we "
            "need to redo client_init (abandon client id %" PRIu64 ")",
            conn->client_id);
        // in this case, the helper indicated it's abandoned the client already,
        // so we can't send the goodbye
        ret = dd_helper_fatal;
        goto error;
    }

    if (response.ptr == NULL) {
        mlog(dd_log_info, "Empty result from dd_trace_send_appsec_message");
        // if the response is empty, that indicates some serious problem with
        // the helper, so we won't try to send the goodbye
        ret = dd_helper_fatal;
        goto error;
    }

    if (response.len < sizeof(dd_header)) {
        mlog(dd_log_warning, "Helper response is too short: %zu", response.len);
        ret = dd_helper_say_goobye;
        goto error;
    }
    dd_header h;
    memcpy(&h, response.ptr, sizeof(h));
    if (memcmp(h.code, "dds", 4) != 0) {
        mlog(dd_log_warning, "Helper response has invalid magic: %s", h.code);
        ret = dd_helper_say_goobye;
        goto error;
    }

    if (h.size > MAX_RECV_MESSAGE_SIZE) {
        mlog(dd_log_warning,
            "Helper response exceed the maximum size: %" PRIu32, h.size);
        ret = dd_helper_say_goobye;
        goto error;
    }
    size_t expected_size = sizeof(dd_header) + h.size;
    if (expected_size != response.len) {
        mlog(dd_log_warning,
            "Helper response length mismatch: expected %zu, got %zu",
            expected_size, response.len);
        ret = dd_helper_say_goobye;
        goto error;
    }

    *response_out = (dd_helper_response){
        .data = (char *)response.ptr + sizeof(dd_header),
        .data_len = h.size,
        ._capacity = response.capacity,
    };
    return dd_success;

error:
    if (response.ptr != NULL) {
        dd_trace_free_appsec_message_response(response);
    }
    return ret;
}

void dd_helper_response_destroy(dd_helper_response *nonnull response)
{
    if (response->data == NULL) {
        return;
    }

    dd_trace_free_appsec_message_response((ddog_AppsecCResponse){
        .ptr = (uint8_t *)response->data - sizeof(dd_header),
        .len = response->data_len + sizeof(dd_header),
        .capacity = response->_capacity,
        .disconnect = false,
    });
}

void dd_conn_destroy(dd_conn *nonnull conn)
{
    conn->connected = false;
    conn->client_id = 0;
}
