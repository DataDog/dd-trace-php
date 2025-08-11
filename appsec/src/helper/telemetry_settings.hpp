#pragma once

#include "utils.hpp"
#include <msgpack.hpp>
#include <spdlog/fmt/bundled/base.h>
#include <string>

namespace dds {

struct telemetry_settings {
    std::string service_name;
    std::string env_name;

    bool operator==(const telemetry_settings &other) const
    {
        return service_name == other.service_name && env_name == other.env_name;
    }

    MSGPACK_DEFINE_MAP(service_name, env_name)
};

} // namespace dds

template <> struct fmt::formatter<dds::telemetry_settings> {
    constexpr auto parse(fmt::format_parse_context &ctx) { return ctx.begin(); }

    template <typename FormatContext>
    auto format(const dds::telemetry_settings &s, FormatContext &ctx) const
    {
        return fmt::format_to(ctx.out(), "{{service_name={}, env_name={}}}",
            s.service_name, s.env_name);
    }
};

namespace std {
template <> struct hash<dds::telemetry_settings> {
    size_t operator()(const dds::telemetry_settings &s) const noexcept
    {
        return dds::hash(s.service_name, s.env_name);
    }
};
} // namespace std
