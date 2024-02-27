// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#include "rate_limit.hpp"

#include <chrono>

using std::chrono::duration_cast;
using std::chrono::microseconds;
using std::chrono::milliseconds;
using std::chrono::seconds;

namespace dds {
rate_limiter::rate_limiter(unsigned max_per_second)
    : max_per_second_(max_per_second)
{
    timer_ = std::make_unique<dds::timer>();
}

bool rate_limiter::allow()
{
    if (max_per_second_ == 0) {
        return true;
    }

    auto time_since_epoch = timer_->time_since_epoch();
    auto now_ms = duration_cast<milliseconds>(time_since_epoch).count();
    auto now_s = duration_cast<seconds>(time_since_epoch).count();

    std::lock_guard<std::mutex> const lock(mtx_);

    if (now_s != index_) {
        if (index_ == now_s - 1) {
            precounter_ = counter_;
        } else {
            precounter_ = 0;
        }
        counter_ = 0;
        index_ = now_s;
    }

    constexpr uint64_t mil = 1000;
    uint32_t const count =
        (precounter_ * (mil - (now_ms % mil))) / mil + counter_;

    if (count >= max_per_second_) {
        return false;
    }

    counter_++;

    return true;
}

} // namespace dds
