// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <iostream>
#include <queue>
#include <rate_limit.hpp>
#include <timer.hpp>

namespace dds {

namespace mock {

class timer : public dds::timer {
public:
    system_clock::duration time_since_epoch()
    {
        if (responses.empty()) {
            return system_clock::duration(0);
        }
        auto result = responses.front();

        if (responses.size() >
            1) { // Last element will be used during the rest of the calls
            responses.pop();
        }

        return result;
    }
    ~timer() = default;
    std::queue<system_clock::duration> responses;
};

class rate_limiter : public dds::rate_limiter {
public:
    explicit rate_limiter(uint32_t max_per_second)
        : dds::rate_limiter(max_per_second)
    {}
    void set_timer(std::unique_ptr<mock::timer> &&timer)
    {
        timer_ = std::move(timer);
    }
};
} // namespace mock

TEST(RateLimitTest, OnlyAllowedMaxPerSecond)
{
    auto first_round_time = system_clock::now();
    auto second_round_time = first_round_time + std::chrono::seconds(5);

    std::unique_ptr<mock::timer> timer = std::make_unique<mock::timer>();
    // Four calls within the same second
    timer->responses.push(first_round_time.time_since_epoch());

    mock::rate_limiter rate_limiter(2);
    rate_limiter.set_timer(std::move(timer));

    int allowed = 0;
    for (int i = 0; i < 10; i++) {
        if (rate_limiter.allow()) {
            allowed++;
        }
    }
    EXPECT_EQ(2, allowed);

    timer = std::make_unique<mock::timer>();
    timer->responses.push(second_round_time.time_since_epoch());
    rate_limiter.set_timer(std::move(timer));

    allowed = 0;
    for (int i = 0; i < 10; i++) {
        if (rate_limiter.allow()) {
            allowed++;
        }
    }
    EXPECT_EQ(2, allowed);
}

TEST(RateLimitTest, WhenNotMaxPerSecondItAlwaysAllow)
{
    dds::rate_limiter rate_limiter(0);

    EXPECT_TRUE(rate_limiter.allow());
    EXPECT_TRUE(rate_limiter.allow());
    EXPECT_TRUE(rate_limiter.allow());
    EXPECT_TRUE(rate_limiter.allow());
}

} // namespace dds
