// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <php.h>

#include "../commands_helpers.h"
#include "../ddappsec.h"
#include "../logging.h"
#include "../msgpack_helpers.h"
#include "client_init.h"
#include "mpack-common.h"
#include "mpack-node.h"

static dd_result _pack_command(mpack_writer_t *nonnull w, void *nullable ctx);
static dd_result _process_response(mpack_node_t root, void *nullable ctx);

static const dd_command_spec _spec = {
    .name = "client_init",
    .name_len = sizeof("client_init") - 1,
    .num_args = 4,
    .outgoing_cb = _pack_command,
    .incoming_cb = _process_response,
};

dd_result dd_client_init(dd_conn *nonnull conn)
{
    return dd_command_exec_cred(conn, &_spec, NULL);
}

static dd_result _pack_command(
    mpack_writer_t *nonnull w, ATTR_UNUSED void *nullable ctx)
{
    // unsigned pid, string client_version, runtime_version, rules_file
    mpack_write(w, (uint32_t)getpid());
    dd_mpack_write_lstr(w, PHP_DDAPPSEC_VERSION);
    dd_mpack_write_lstr(w, PHP_VERSION);

    const char *rules_file = DDAPPSEC_G(rules_file);
    bool has_rules_file = rules_file && *rules_file;

    if (!has_rules_file) {
        mlog(dd_log_info,
            "datadog.appsec.rules_path was not provided. The helper "
            "will atttempt to use the default file");
    }
    dd_mpack_write_nullable_cstr(w, rules_file);

    return dd_success;
}

static dd_result _process_response(
    mpack_node_t root, ATTR_UNUSED void *nullable ctx)
{
    mpack_node_t verdict = mpack_node_array_at(root, 0);
    bool is_ok = dd_mpack_node_lstr_eq(verdict, "ok");
    if (is_ok) {
        mlog(dd_log_debug, "Response to client_init is ok");
        return dd_success;
    }

    // not ok, in which case expect at least one error message

    const char *ver = mpack_node_str(verdict);
    size_t verlen = mpack_node_strlen(verdict);

    mpack_node_t errors = mpack_node_array_at(root, 1);
    mpack_node_t first_error_node = mpack_node_array_at(errors, 0);
    const char *first_error = mpack_node_str(first_error_node);
    size_t first_error_len = mpack_node_strlen(first_error_node);

    mpack_error_t err = mpack_node_error(verdict);
    if (err != mpack_ok) {
        mlog(dd_log_warning, "Unexpected client_init response: %s",
            mpack_error_to_string(err));
    } else {
        if (verlen > INT_MAX) {
            verlen = INT_MAX;
        }
        if (first_error_len > INT_MAX) {
            first_error_len = INT_MAX;
        }

        mlog(dd_log_warning, "Response to client_init is not ok: %.*s: %.*s",
            (int)verlen, ver, (int)first_error_len, first_error);
    }

    return dd_error;
}
