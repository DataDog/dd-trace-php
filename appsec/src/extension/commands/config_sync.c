// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// NOLINTNEXTLINE(misc-header-include-cycle)
#include <php.h>

#include "../commands_helpers.h"
#include "../ddtrace.h"
#include "../logging.h"
#include "../msgpack_helpers.h"
#include "config_sync.h"

static dd_result _request_pack(mpack_writer_t *nonnull w, void *nonnull ctx);
dd_result dd_command_process_config_sync(
    mpack_node_t root, ATTR_UNUSED void *unspecnull ctx);

static const dd_command_spec _spec = {
    .name = "config_sync",
    .name_len = sizeof("config_sync") - 1,
    .num_args = 2,
    .outgoing_cb = _request_pack,
    .incoming_cb = dd_command_process_config_sync,
    .config_features_cb = dd_command_process_config_features,
};

dd_result dd_config_sync(
    dd_conn *nonnull conn, const struct config_sync_data *nonnull data)
{
    mlog_g(dd_log_debug,
        "Sending config sync request to the helper with path=%s, "
        "service_name=%.*s, env_name=%.*s",
        data->rem_cfg_path, ZSTR_PRINTF(data->telemetry_settings.service_name),
        ZSTR_PRINTF(data->telemetry_settings.env_name));

    return dd_command_exec(conn, &_spec, (void *)data);
}

static dd_result _request_pack(mpack_writer_t *nonnull w, void *nonnull ctx_)
{
    const struct config_sync_data *nonnull data =
        (struct config_sync_data *)ctx_;

    // 1. rem_cfg_path
    dd_mpack_write_nullable_cstr(w, data->rem_cfg_path);

    // 2. telemetry_settings
    mpack_start_map(w, 2);
    dd_mpack_write_lstr(w, "service_name");
    dd_mpack_write_nullable_zstr(w, data->telemetry_settings.service_name);
    dd_mpack_write_lstr(w, "env_name");
    dd_mpack_write_nullable_zstr(w, data->telemetry_settings.env_name);
    mpack_finish_map(w); // telemetry_settings

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
