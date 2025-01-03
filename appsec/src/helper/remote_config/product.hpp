// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../utils.hpp"
#include <spdlog/spdlog.h>
#include <string_view>

namespace dds::remote_config {

class product {
public:
    explicit constexpr product(std::string_view name) : name_{name} {}

    [[nodiscard]] const std::string_view &name() const { return name_; }

    bool operator==(const product &other) const { return name_ == other.name_; }

private:
    std::string_view name_;
};

struct known_products {
    static inline constexpr product ASM{std::string_view{"ASM"}};
    static inline constexpr product ASM_DD{std::string_view{"ASM_DD"}};
    static inline constexpr product ASM_DATA{std::string_view{"ASM_DATA"}};
    static inline constexpr product ASM_FEATURES{
        std::string_view{"ASM_FEATURES"}};
    static inline constexpr product ASM_RASP_LFI{
        std::string_view{"ASM_RASP_LFI"}};
    static inline constexpr product ASM_RASP_SSRF{
        std::string_view{"ASM_RASP_SSRF"}};
    static inline constexpr product UNKNOWN{std::string_view{"UNKOWN"}};

    static product for_name(std::string_view name)
    {
        if (name == ASM.name()) {
            return ASM;
        }
        if (name == ASM_DD.name()) {
            return ASM_DD;
        }
        if (name == ASM_DATA.name()) {
            return ASM_DATA;
        }
        if (name == ASM_FEATURES.name()) {
            return ASM_FEATURES;
        }
        if (name == ASM_RASP_LFI.name()) {
            return ASM_RASP_LFI;
        }
        if (name == ASM_RASP_SSRF.name()) {
            return ASM_RASP_SSRF;
        }

        return UNKNOWN;
    }
};
} // namespace dds::remote_config

template <>
struct fmt::formatter<dds::remote_config::product>
    : fmt::formatter<std::string_view> {

    auto format(const dds::remote_config::product &p, format_context &ctx) const
    {
        auto name = p.name();
        return formatter<std::string_view>::format(name, ctx);
    }
};

namespace std {
template <> struct hash<dds::remote_config::product> {
    std::size_t operator()(const dds::remote_config::product &product) const
    {
        return dds::hash(product.name());
    }
};
} // namespace std
