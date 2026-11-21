// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../commands_ctx.h"
#include "../dddefs.h"

struct req_info_init {
    struct req_info req_info;
    zend_array *nullable superglob_equiv;
    zend_string *nullable entity;
};
// Without keep_bound_application, the helper binds to the current application
// first; see AppSecRequest::Init.
dd_result dd_request_init(
    struct req_info_init *nonnull ctx, bool keep_bound_application);
