// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "request_exec.h"
#include "../commands_helpers.h"
#include "../ddappsec.h"
#include "../logging.h"
#include "../msgpack_helpers.h"
#include <php.h>
#include <zend_hash.h>
#include <zend_types.h>

struct ctx {
    struct req_info req_info; // dd_command_proc_resp_verd_span_data expect it
    zend_string *nullable rasp_rule;
    zval *nonnull data;
};

static dd_result _pack_command(mpack_writer_t *nonnull w, void *nonnull ctx);

static const dd_command_spec _spec = {
    .name = "request_exec",
    .name_len = sizeof("request_exec") - 1,
    .num_args = 2,
    .outgoing_cb = _pack_command,
    .incoming_cb = dd_command_proc_resp_verd_span_data,
    .config_features_cb = dd_command_process_config_features_unexpected,
};

dd_result dd_request_exec(
    dd_conn *nonnull conn, zval *nonnull data, zend_string *nullable rasp_rule)
{
    if (Z_TYPE_P(data) != IS_ARRAY) {
        mlog(dd_log_debug, "Invalid data provided to command request_exec, "
                           "expected hash table.");
        return dd_error;
    }

    struct ctx ctx = {.rasp_rule = rasp_rule, .data = data};

    return dd_command_exec_req_info(conn, &_spec, &ctx.req_info);
}

static dd_result _pack_command(mpack_writer_t *nonnull w, void *nonnull _ctx)
{
    assert(_ctx != NULL);
    struct ctx *ctx = _ctx;

    dd_mpack_write_nullable_zstr(w, ctx->rasp_rule);
    dd_mpack_write_zval(w, ctx->data);

    return dd_success;
}
