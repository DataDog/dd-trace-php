// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "utils.hpp"
#include <msgpack.hpp>
#include <string>

namespace dds {
struct service_identifier {
    std::string service;
    std::string env;
    std::string tracer_version;
    std::string app_version;
    std::string runtime_id;

    MSGPACK_DEFINE_MAP(service, env);

    bool operator==(const service_identifier &oth) const noexcept
    {
        return service == oth.service && env == oth.env &&
               tracer_version == oth.tracer_version &&
               app_version == oth.app_version && runtime_id == oth.runtime_id;
    }

    friend auto &operator<<(std::ostream &os, const service_identifier &id)
    {
        return os << "{service=" << id.service << ", env=" << id.env
                  << ", tracer_version=" << id.tracer_version
                  << ", app_version=" << id.app_version
                  << ", runtime_id=" << id.runtime_id << "}";
    }

    struct hash {
        std::size_t operator()(const service_identifier &id) const noexcept
        {
            return dds::hash(id.service, id.env, id.tracer_version,
                id.app_version, id.runtime_id);
        }
    };
};
} // namespace dds
