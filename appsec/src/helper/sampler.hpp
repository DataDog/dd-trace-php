// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include <atomic>
#include <cmath>
#include <cstdint>
#include <mutex>

namespace dds {
static const double min_rate = 0.0001;
class sampler {
public:
    sampler(double sample_rate) : sample_rate_(sample_rate)
    {
        // NOLINTBEGIN(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
        if (sample_rate_ <= 0) {
            sample_rate_ = 0;
        } else if (sample_rate_ > 1) {
            sample_rate_ = 1;
        } else if (sample_rate_ < min_rate) {
            sample_rate_ = min_rate;
        }
        // NOLINTEND(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    }

    bool picked()
    {
        if (sample_rate_ == 1) {
            return true;
        }

        auto old_request = request_.fetch_add(1, std::memory_order_relaxed);
        return floor(old_request * sample_rate_) !=
               floor((request_)*sample_rate_);
    }

protected:
    std::atomic<unsigned> request_{0};
    double sample_rate_;
    std::mutex mtx_;
};
} // namespace dds
