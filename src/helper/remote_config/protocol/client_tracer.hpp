// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <string>

namespace dds::remote_config::protocol {

struct client_tracer {
    std::string runtime_id;
    std::string tracer_version;
    std::string service;
    std::string env;
    std::string app_version;
};

inline bool operator==(const client_tracer &rhs, const client_tracer &lhs)
{
    return rhs.runtime_id == lhs.runtime_id &&
           rhs.tracer_version == lhs.tracer_version &&
           rhs.service == lhs.service && rhs.env == lhs.env &&
           rhs.app_version == lhs.app_version;
}

} // namespace dds::remote_config::protocol
