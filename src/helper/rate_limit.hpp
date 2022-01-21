// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include <atomic>
#include <mutex>

namespace dds {

class rate_limiter {
public:
    explicit rate_limiter(uint32_t max_per_second);
    bool allow();

protected:
    std::mutex mtx_;
    uint32_t index_{0};
    uint32_t counter_{0};
    uint32_t precounter_{0};
    const uint32_t max_per_second_;
};

} // namespace dds
