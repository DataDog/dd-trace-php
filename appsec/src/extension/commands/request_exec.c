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
#include <mpack.h>
#include <php.h>
#include <zend_hash.h>
#include <zend_types.h>

struct ctx {
    struct req_info req_info; // dd_command_proc_resp_verd_span_data expect it
    zend_string *nullable rasp_rule;
    zend_string *nullable subctx_id;
    bool subctx_last_call;
    zend_array *nonnull data;
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

dd_result dd_request_exec(dd_conn *nonnull conn, zend_array *nonnull data,
    const struct req_exec_opts *nonnull opts,
    struct block_params *nonnull block_params)
{
    struct ctx ctx = {.data = data,
        .rasp_rule = opts->rasp_rule,
        .subctx_id = opts->subctx_id,
        .subctx_last_call = opts->subctx_last_call};

    dd_result res = dd_command_exec_req_info(conn, &_spec, &ctx.req_info);

    memcpy(block_params, &ctx.req_info.block_params, sizeof *block_params);

    return res;
}

static dd_result _pack_command(mpack_writer_t *nonnull w, void *nonnull _ctx)
{
    assert(_ctx != NULL);
    struct ctx *ctx = _ctx;

    dd_mpack_limits limits = dd_mpack_def_limits;
    dd_mpack_write_array_lim(w, ctx->data, &limits);

    size_t num_map_elems =
        (ctx->rasp_rule != NULL) + (ctx->subctx_id != NULL) * 2;
    mpack_start_map(w, num_map_elems);

    if (dd_mpack_limits_reached(&limits)) {
        mlog(dd_log_info, "Limits reched when serializing request exec data");
    }

    if (ctx->rasp_rule != NULL) {
        dd_mpack_write_lstr(w, "rasp_rule");
        dd_mpack_write_zstr(w, ctx->rasp_rule);
    }

    if (ctx->subctx_id != NULL) {
        dd_mpack_write_lstr(w, "subctx_id");
        dd_mpack_write_nullable_zstr(w, ctx->subctx_id);

        dd_mpack_write_lstr(w, "subctx_last_call");
        mpack_write_bool(w, ctx->subctx_last_call);
    }

    mpack_finish_map(w);

    return dd_success;
}
