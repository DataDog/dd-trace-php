// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <vector>

#include "cached_target_file_hash.hpp"

namespace dds::remote_config::protocol {

struct cached_target_files {
    std::string path;
    int length;
    std::vector<cached_target_files_hash> hashes;
};

inline bool operator==(
    const cached_target_files &rhs, const cached_target_files &lhs)
{
    return rhs.path == lhs.path && rhs.length == lhs.length &&
           rhs.hashes == lhs.hashes;
}

} // namespace dds::remote_config::protocol
