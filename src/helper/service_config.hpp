// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <atomic>
#include <map>
#include <optional>
#include <unordered_map>

namespace dds {

enum class enable_asm_status : unsigned { NOT_SET = 0, ENABLED, DISABLED };

struct service_config {
    void enable_asm() { asm_enabled = enable_asm_status::ENABLED; }
    void disable_asm() { asm_enabled = enable_asm_status::DISABLED; }
    void unset_asm() { asm_enabled = enable_asm_status::NOT_SET; }
    enable_asm_status get_asm_enabled_status() { return asm_enabled; }

protected:
    std::atomic<enable_asm_status> asm_enabled = {enable_asm_status::NOT_SET};
};

} // namespace dds
