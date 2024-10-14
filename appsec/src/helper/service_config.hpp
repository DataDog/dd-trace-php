// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <atomic>
#include <map>
#include <optional>
#include <spdlog/spdlog.h>
#include <unordered_map>

namespace dds {

enum class enable_asm_status : unsigned { NOT_SET = 0, ENABLED, DISABLED };

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

    enable_asm_status get_asm_enabled_status() { return asm_enabled; }

protected:
    std::atomic<enable_asm_status> asm_enabled = {enable_asm_status::NOT_SET};
};

} // namespace dds
