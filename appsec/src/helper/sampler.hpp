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
    sampler(std::shared_ptr<service_config> service_config)
        : service_config_(std::move(service_config))
    {
        set_sampler_rate(service_config_->get_request_sample_rate());
    }
    class scope {
    public:
        explicit scope(std::atomic<bool> &concurrent) : concurrent_(&concurrent)
        {
            concurrent_->store(true, std::memory_order_relaxed);
        }

        scope(const scope &) = delete;
        scope &operator=(const scope &) = delete;
        scope(scope &&oth) noexcept
        {
            concurrent_ = oth.concurrent_;
            oth.concurrent_ = nullptr;
        }
        scope &operator=(scope &&oth)
        {
            concurrent_ = oth.concurrent_;
            oth.concurrent_ = nullptr;

            return *this;
        }

        ~scope()
        {
            if (concurrent_ != nullptr) {
                concurrent_->store(false, std::memory_order_relaxed);
            }
        }

    protected:
        std::atomic<bool> *concurrent_;
    };

    std::optional<scope> get()
    {
        const std::lock_guard<std::mutex> lock_guard(mtx_);

        std::optional<scope> result = std::nullopt;

        if (sample_rate_ !=
            valid_sample_rate(service_config_->get_request_sample_rate())) {
            set_sampler_rate(service_config_->get_request_sample_rate());
        }

        if (!concurrent_ && floor(request_ * sample_rate_) !=
                                floor((request_ + 1) * sample_rate_)) {
            result = {scope{concurrent_}};
        }

        if (request_ < std::numeric_limits<unsigned>::max()) {
            request_++;
        } else {
            request_ = 1;
        }

        return result;
    }

protected:
    static double valid_sample_rate(double sampler_rate)
    {
        // NOLINTBEGIN(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
        if (sampler_rate <= 0) {
            sampler_rate = 0;
        } else if (sampler_rate > 1) {
            sampler_rate = 1;
        } else if (sampler_rate < min_rate) {
            sampler_rate = min_rate;
        }
        // NOLINTEND(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)

        return sampler_rate;
    }

    void set_sampler_rate(double sampler_rate)
    {
        sampler_rate = valid_sample_rate(sampler_rate);

        if (sampler_rate == sample_rate_) {
            return;
        }

        request_ = 1;
        sample_rate_ = sampler_rate;
    }
    unsigned request_{1};
    double sample_rate_{0};
    std::atomic<bool> concurrent_{false};
    std::mutex mtx_;
    std::shared_ptr<service_config> service_config_;
};
} // namespace dds
