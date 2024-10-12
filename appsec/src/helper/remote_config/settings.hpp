// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#pragma once

#include "../utils.hpp"
#include <algorithm>
#include <cstdint>
#include <msgpack.hpp>
#include <ostream>
#include <string>

namespace dds::remote_config {

struct settings {
    bool enabled{};
    std::string shmem_path;

    bool operator==(const settings &oth) const noexcept
    {
        return enabled == oth.enabled && shmem_path == oth.shmem_path;
    }

    friend auto &operator<<(std::ostream &os, const settings &c)
    {
        return os << "{enabled=" << std::boolalpha << c.enabled
                  << ", shmem_path=" << c.shmem_path << "}";
    }

    MSGPACK_DEFINE_MAP(enabled, shmem_path);
};
} // namespace dds::remote_config

namespace std {
template <> struct hash<dds::remote_config::settings> {
    std::size_t operator()(const dds::remote_config::settings &s) const noexcept
    {
        return dds::hash(s.enabled, s.shmem_path);
    }
};

} // namespace std
