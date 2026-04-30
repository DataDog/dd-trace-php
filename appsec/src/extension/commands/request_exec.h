// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <SAPI.h>
#include <php.h>

#include "../network.h"
#include "../request_abort.h"

struct req_exec_opts {
    zend_string *nullable rasp_rule;
    zend_string *nullable subctx_id;
    bool subctx_last_call;
};

dd_result dd_request_exec(dd_conn *nonnull conn, zend_array *nonnull data,
    const struct req_exec_opts *nonnull opts,
    struct block_params *nonnull block_params);
