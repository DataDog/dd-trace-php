// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "protocol/config_state.hpp"
#include <atomic>

namespace dds::remote_config {

struct remote_config_service {
    void enable_asm() { asm_enabled = true; }
    void disable_asm() { asm_enabled = false; }
    bool is_asm_enabled() { return asm_enabled; }

protected:
    std::atomic<bool> asm_enabled = {false};
};

} // namespace dds::remote_config
