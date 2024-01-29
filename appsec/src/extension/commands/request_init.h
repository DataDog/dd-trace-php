// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include "../network.h"
#include "../commands_ctx.h"

struct req_info_init {
    struct req_info req_info;
    zend_array *nullable superglob_equiv;
};
dd_result dd_request_init(
    dd_conn *nonnull conn, struct req_info_init *nonnull ctx);
