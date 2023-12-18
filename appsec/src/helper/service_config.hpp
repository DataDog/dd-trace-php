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
    double get_request_sample_rate() { return request_sample_rate; }
    void set_request_sample_rate(double sample_rate)
    {
        if (sample_rate < 0 || sample_rate > 1) {
            return;
        }
        request_sample_rate = sample_rate;
    }

protected:
    std::atomic<enable_asm_status> asm_enabled = {enable_asm_status::NOT_SET};
    std::atomic<double> request_sample_rate = 0.1;
};

} // namespace dds
