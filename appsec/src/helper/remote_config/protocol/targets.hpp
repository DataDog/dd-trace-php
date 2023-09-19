// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <string>
#include <unordered_map>

#include "path.hpp"

namespace dds::remote_config::protocol {

struct targets {
    int version;
    std::string opaque_backend_state;
    std::unordered_map<std::string, path> paths;
};

inline bool operator==(const targets &rhs, const targets &lhs)
{
    return rhs.version == lhs.version &&
           rhs.opaque_backend_state == lhs.opaque_backend_state &&
           std::equal(lhs.paths.begin(), lhs.paths.end(), rhs.paths.begin());
}

} // namespace dds::remote_config::protocol
