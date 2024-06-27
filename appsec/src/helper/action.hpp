// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <string>

namespace dds {

enum class action_type : unsigned int {
    invalid = 0,
    record = 1,
    redirect = 2,
    block = 3,
    stack_trace = 4,
    extract_schema = 5
};

} // namespace dds
