// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <string>
#include <vector>

namespace dds::remote_config::protocol {

struct target_file {
    std::string path;
    std::string raw;
};

inline bool operator==(const target_file &rhs, const target_file &lhs)
{
    return rhs.path == lhs.path && rhs.raw == lhs.raw;
}

} // namespace dds::remote_config::protocol
