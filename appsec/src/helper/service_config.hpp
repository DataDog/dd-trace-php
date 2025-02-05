// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <atomic>
#include <spdlog/spdlog.h>

namespace dds {
enum class enable_asm_status : unsigned { NOT_SET = 0, ENABLED, DISABLED };
enum class auto_user_instrum_mode : unsigned {
    UNDEFINED = 0,
    UNKNOWN,
    DISABLED,
    IDENTIFICATION,
    ANONYMIZATION
};
} // namespace dds

template <>
struct fmt::formatter<std::atomic<dds::enable_asm_status>>
    : fmt::formatter<std::string_view> {
    auto format(const std::atomic<dds::enable_asm_status> &status,
        format_context &ctx) const
    {
        auto val = status.load();
        std::string_view name{"UNKNOWN"};
        switch (val) {
        case dds::enable_asm_status::NOT_SET:
            name = "NOT_SET";
            break;
        case dds::enable_asm_status::ENABLED:
            name = "ENABLED";
            break;
        case dds::enable_asm_status::DISABLED:
            name = "DISABLED";
            break;
        }

        return fmt::formatter<std::string_view>::format(name, ctx);
    }
};

template <>
struct fmt::formatter<std::atomic<dds::auto_user_instrum_mode>>
    : fmt::formatter<std::string_view> {
    auto format(const std::atomic<dds::auto_user_instrum_mode> &mode,
        format_context &ctx) const
    {
        auto val = mode.load();
        std::string_view name{""};
        switch (val) {
        case dds::auto_user_instrum_mode::UNDEFINED:
            name = "UNDEFINED";
            break;
        case dds::auto_user_instrum_mode::UNKNOWN:
            name = "UNKNOWN";
            break;
        case dds::auto_user_instrum_mode::DISABLED:
            name = "DISABLED";
            break;
        case dds::auto_user_instrum_mode::IDENTIFICATION:
            name = "IDENTIFICATION";
            break;
        case dds::auto_user_instrum_mode::ANONYMIZATION:
            name = "ANONYMIZATION";
            break;
        }

        return fmt::formatter<std::string_view>::format(name, ctx);
    }
};

namespace dds {

inline std::string_view to_string_view(enable_asm_status status)
{
    if (status == enable_asm_status::NOT_SET) {
        return "NOT_SET";
    }
    if (status == enable_asm_status::ENABLED) {
        return "ENABLED";
    }
    if (status == enable_asm_status::DISABLED) {
        return "DISABLED";
    }
    return "UNKNOWN";
}

inline std::string_view to_string_view(auto_user_instrum_mode mode)
{
    if (mode == auto_user_instrum_mode::UNDEFINED) {
        return "UNDEFINED";
    }
    if (mode == auto_user_instrum_mode::DISABLED) {
        return "DISABLED";
    }
    if (mode == auto_user_instrum_mode::IDENTIFICATION) {
        return "IDENTIFICATION";
    }
    if (mode == auto_user_instrum_mode::ANONYMIZATION) {
        return "ANONYMIZATION";
    }
    return "UNKNOWN";
}

struct service_config {
    void enable_asm()
    {
        SPDLOG_DEBUG("Enabling ASM, previous state: {}", asm_enabled);
        asm_enabled = enable_asm_status::ENABLED;
    }

    void disable_asm()
    {
        SPDLOG_DEBUG("Disabling ASM, previous state: {}", asm_enabled);
        asm_enabled = enable_asm_status::DISABLED;
    }

    void unset_asm()
    {
        SPDLOG_DEBUG("Unsetting ASM status, previous state: {}", asm_enabled);
        asm_enabled = enable_asm_status::NOT_SET;
    }

    void set_auto_user_instrum(auto_user_instrum_mode mode)
    {
        SPDLOG_DEBUG("Setting auto_user_instrum mode, previous state: {}",
            auto_user_instrum);
        auto_user_instrum = mode;
    }

    void unset_auto_user_instrum()
    {
        SPDLOG_DEBUG("Unsetting auto_user_instrum mode, previous state: {}",
            auto_user_instrum);
        auto_user_instrum = auto_user_instrum_mode::UNDEFINED;
    }

    enable_asm_status get_asm_enabled_status() { return asm_enabled; }
    auto_user_instrum_mode get_auto_user_intrum_mode()
    {
        return auto_user_instrum;
    }

protected:
    std::atomic<enable_asm_status> asm_enabled = {enable_asm_status::NOT_SET};
    std::atomic<auto_user_instrum_mode> auto_user_instrum = {
        auto_user_instrum_mode::UNDEFINED};
};

} // namespace dds