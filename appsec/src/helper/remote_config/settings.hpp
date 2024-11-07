// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#pragma once

#include "../utils.hpp"
#include <msgpack.hpp>
#include <ostream>
#include <spdlog/fmt/bundled/base.h>
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

template <> struct fmt::formatter<dds::remote_config::settings> {
    constexpr auto parse(fmt::format_parse_context &ctx) { return ctx.begin(); }

    template <typename FormatContext>
    auto format(const dds::remote_config::settings &s, FormatContext &ctx) const
    {
        return fmt::format_to(ctx.out(), "{{enabled={}, shmem_path={}}}",
            s.enabled, s.shmem_path);
    }
};

namespace std {
template <> struct hash<dds::remote_config::settings> {
    std::size_t operator()(const dds::remote_config::settings &s) const noexcept
    {
        return dds::hash(s.enabled, s.shmem_path);
    }
};

} // namespace std
