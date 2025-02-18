// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "attributes.h"
#include "commands_ctx.h"
#include "dddefs.h"
#include "network.h"
#include <mpack.h>

typedef struct _dd_command_spec {
    const char *nonnull name;
    size_t name_len;
    size_t num_args; // outgoing args
    dd_result (*nonnull outgoing_cb)(
        mpack_writer_t *nonnull writer, void *unspecnull ctx);
    dd_result (*nonnull incoming_cb)(mpack_node_t root, void *unspecnull ctx);
    dd_result (*nonnull config_features_cb)(
        mpack_node_t root, void *unspecnull ctx);
} dd_command_spec;

dd_result ATTR_WARN_UNUSED dd_command_exec(dd_conn *nonnull conn,
    const dd_command_spec *nonnull spec, void *unspecnull ctx);

dd_result ATTR_WARN_UNUSED dd_command_exec_req_info(dd_conn *nonnull conn,
    const dd_command_spec *nonnull spec, struct req_info *nonnull ctx);

dd_result ATTR_WARN_UNUSED dd_command_exec_cred(dd_conn *nonnull conn,
    const dd_command_spec *nonnull spec, void *unspecnull ctx);

/* Baked response */
dd_result dd_command_proc_resp_verd_span_data(mpack_node_t root,
    void *unspecnull ctx /* actually struct req_info* nonnull */);

/* Common helpers */
void dd_command_process_meta(mpack_node_t root, zend_object *nonnull span);
bool dd_command_process_metrics(mpack_node_t root, zend_object *nonnull span);
bool dd_command_process_telemetry_metrics(mpack_node_t root);
dd_result dd_command_process_config_features(
    mpack_node_t root, ATTR_UNUSED void *nullable ctx);
dd_result dd_command_process_config_features_unexpected(
    mpack_node_t root, ATTR_UNUSED void *nullable ctx);
