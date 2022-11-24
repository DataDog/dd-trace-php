// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <string>

namespace dds::remote_config::protocol {

struct config_state {
    std::string id;
    int version;
    std::string product;
    enum class applied_state : int {
        UNKNOWN = 0,
        UNACKNOWLEDGED = 1,
        ACKNOWLEDGED = 2,
        ERROR = 3
    } apply_state;
    std::string apply_error;
};

inline bool operator==(const config_state &rhs, const config_state &lhs)
{
    return rhs.id == lhs.id && rhs.version == lhs.version &&
           rhs.product == lhs.product && rhs.apply_state == lhs.apply_state &&
           rhs.apply_error == lhs.apply_error;
}

} // namespace dds::remote_config::protocol
