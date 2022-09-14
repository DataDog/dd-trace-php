// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "config_state.hpp"
#include <string>
#include <vector>

namespace dds::remote_config::protocol {

struct client_state {
    int targets_version;
    std::vector<config_state> config_states;
    bool has_error;
    std::string error;
    std::string backend_client_state;
};

inline bool operator==(const client_state &rhs, const client_state &lhs)
{
    return rhs.targets_version == lhs.targets_version &&
           rhs.config_states == lhs.config_states &&
           rhs.has_error == lhs.has_error && rhs.error == lhs.error &&
           rhs.backend_client_state == lhs.backend_client_state;
}

} // namespace dds::remote_config::protocol
