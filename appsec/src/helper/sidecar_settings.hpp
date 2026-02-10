// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "utils.hpp"
#include <msgpack.hpp>
#include <spdlog/fmt/bundled/base.h>
#include <string>

namespace dds {

struct sidecar_settings {
    std::string session_id;
    std::string runtime_id;

    [[nodiscard]] bool is_valid() const
    {
        return !session_id.empty() && !runtime_id.empty();
    }

    bool operator==(const sidecar_settings &other) const
    {
        return session_id == other.session_id && runtime_id == other.runtime_id;
    }

    MSGPACK_DEFINE_MAP(session_id, runtime_id)
};

} // namespace dds

template <> struct fmt::formatter<dds::sidecar_settings> {
    constexpr auto parse(fmt::format_parse_context &ctx) { return ctx.begin(); }

    template <typename FormatContext>
    auto format(const dds::sidecar_settings &s, FormatContext &ctx) const
    {
        return fmt::format_to(ctx.out(), "{{session_id={}, runtime_id={}}}",
            s.session_id, s.runtime_id);
    }
};

namespace std {
template <> struct hash<dds::sidecar_settings> {
    size_t operator()(const dds::sidecar_settings &s) const noexcept
    {
        return dds::hash(s.session_id, s.runtime_id);
    }
};
} // namespace std
