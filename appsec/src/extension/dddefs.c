// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "dddefs.h"

const char *nonnull dd_result_to_string(dd_result result)
{
    switch (result) {
    case dd_success:
        return "dd_success";
    case dd_network:
        return "dd_network";
    case dd_should_block:
        return "dd_should_block";
    case dd_should_redirect:
        return "dd_should_redirect";
    case dd_should_record:
        return "dd_should_record";
    case dd_error:
        return "dd_error";
    case dd_try_later:
        return "dd_try_later";
    case dd_helper_error:
        return "dd_helper_error";
    default:
        return "unknown";
    }
}
