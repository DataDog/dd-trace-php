// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <string>

namespace dds::remote_config::protocol {

struct cached_target_files_hash {
    std::string algorithm;
    std::string hash;
};

inline bool operator==(
    const cached_target_files_hash &rhs, const cached_target_files_hash &lhs)
{
    return rhs.algorithm == lhs.algorithm && rhs.hash == lhs.hash;
}
} // namespace dds::remote_config::protocol
