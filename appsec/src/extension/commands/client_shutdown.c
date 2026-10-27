// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// NOLINTNEXTLINE(misc-header-include-cycle)
#include <php.h>

#include "../commands_helpers.h"
#include "../msgpack_helpers.h"
#include "client_shutdown.h"

static dd_result _pack_command(mpack_writer_t *nonnull w, void *nonnull ctx);
static dd_result _process_response(
    mpack_node_t root, ATTR_UNUSED void *unspecnull ctx);

static const dd_command_spec _spec = {
    .name = "client_shutdown",
    .name_len = sizeof("client_shutdown") - 1,
    .num_args = 1,
    .outgoing_cb = _pack_command,
    .incoming_cb = _process_response,
    .config_features_cb = dd_command_process_config_features_unexpected,
};

dd_result dd_client_shutdown(
    dd_conn *nonnull conn, const struct client_shutdown_data *nonnull data)
{
    return dd_command_exec(conn, &_spec, (void *)data);
}

static dd_result _pack_command(mpack_writer_t *nonnull w, void *nonnull ctx_)
{
    const struct client_shutdown_data *nonnull data =
        (const struct client_shutdown_data *)ctx_;

    mpack_start_map(w, 2);

    dd_mpack_write_lstr(w, "clean");
    mpack_write_bool(w, data->clean);

    dd_mpack_write_lstr(w, "error");
    dd_mpack_write_nullable_cstr(w, data->error);

    mpack_finish_map(w);

    return dd_success;
}

static dd_result _process_response(
    ATTR_UNUSED mpack_node_t root, ATTR_UNUSED void *unspecnull ctx)
{
    return dd_success;
}
