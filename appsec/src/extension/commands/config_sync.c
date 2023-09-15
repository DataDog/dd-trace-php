// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <php.h>

#include "../commands_helpers.h"
#include <mpack.h>

static dd_result _request_pack(
    mpack_writer_t *nonnull w, void *nullable ATTR_UNUSED ctx);
dd_result dd_command_process_config_sync(
    mpack_node_t root, ATTR_UNUSED void *unspecnull ctx);

static const dd_command_spec _spec = {
    .name = "config_sync",
    .name_len = sizeof("config_sync") - 1,
    .num_args = 0, // a single map
    .outgoing_cb = _request_pack,
    .incoming_cb = dd_command_process_config_sync,
    .config_features_cb = dd_command_process_config_features,
};

dd_result dd_config_sync(dd_conn *nonnull conn)
{
    return dd_command_exec(conn, &_spec, NULL);
}

static dd_result _request_pack(
    mpack_writer_t *nonnull w, void *nullable ATTR_UNUSED ctx)
{
    UNUSED(ctx);
    UNUSED(w);

    return dd_success;
}

dd_result dd_command_process_config_sync(
    mpack_node_t root, ATTR_UNUSED void *unspecnull ctx)
{
    UNUSED(root);
    UNUSED(ctx);
    // There is nothing to do here
    return dd_success;
}
