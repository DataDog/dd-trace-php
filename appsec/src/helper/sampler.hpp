// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include <atomic>
#include <cmath>
#include <cstdint>
#include <iostream>
#include <mutex>
#include <optional>

#include "service_config.hpp"

namespace dds {
static const double min_rate = 0.0001;
class sampler {
public:
    sampler(double sampler_rate)
    {
        if (sampler_rate <= 0) {
            sampler_rate = 0;
        } else if (sampler_rate > 1) {
            sampler_rate = 1;
        } else if (sampler_rate < min_rate) {
            sampler_rate = min_rate;
        }

        sample_rate_ = sampler_rate;
    }

    bool get()
    {
        unsigned prev = request_.fetch_add(1, std::memory_order_relaxed);
        return floor(prev * sample_rate_) != floor((prev + 1) * sample_rate_);
    }

    double get_sample_rate() { return sample_rate_; }

    std::atomic<unsigned> request_{0};
    double sample_rate_{0};
};
} // namespace dds
