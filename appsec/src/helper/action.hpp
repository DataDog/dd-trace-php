// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <map>
#include <string>
#include <unordered_map>
#include <vector>

namespace dds {

enum class action_type : unsigned int {
    invalid = 0,
    record = 1,
    redirect = 2,
    block = 3,
    stack_trace = 4,
    extract_schema = 5
};

struct action {
    dds::action_type type;
    std::unordered_map<std::string, std::string> parameters;
};

struct event {
    bool keep = true;
    std::vector<std::string> data;
    std::vector<action> actions;
};
} // namespace dds
