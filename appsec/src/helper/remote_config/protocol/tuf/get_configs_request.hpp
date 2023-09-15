// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <vector>

#include "../cached_target_files.hpp"
#include "../client.hpp"

namespace dds::remote_config::protocol {

struct get_configs_request {
public:
    protocol::client client;
    std::vector<protocol::cached_target_files> cached_target_files;
};

inline bool operator==(
    const get_configs_request &rhs, const get_configs_request &lhs)
{
    return rhs.client == lhs.client &&
           rhs.cached_target_files == lhs.cached_target_files;
}

} // namespace dds::remote_config::protocol
