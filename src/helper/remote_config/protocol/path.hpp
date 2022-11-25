// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <string>
#include <unordered_map>
#include <vector>

namespace dds::remote_config::protocol {

struct path {
    int custom_v;
    std::unordered_map<std::string, std::string> hashes;
    int length;
};

inline bool operator==(const path &rhs, const path &lhs)
{
    return rhs.custom_v == lhs.custom_v && rhs.hashes == lhs.hashes &&
           rhs.length == lhs.length;
}

} // namespace dds::remote_config::protocol
