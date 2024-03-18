// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <chrono>
#include <iostream>
#include <queue>
#include <rate_limit.hpp>
#include <timer.hpp>

namespace dds {

namespace mock {

class timer : public dds::timer {
public:
    system_clock::duration time_since_epoch() { return time; }
    ~timer() = default;
    system_clock::duration time;
};

class rate_limiter : public dds::rate_limiter<mock::timer> {
public:
    explicit rate_limiter(uint32_t max_per_second)
        : dds::rate_limiter<mock::timer>(max_per_second)
    {}
    void set_timer(mock::timer timer) { timer_ = timer; }
};
} // namespace mock

TEST(RateLimitTest, OnlyAllowedMaxPerSecondNonConsecutiveSeconds)
{
    auto first_round_time = system_clock::duration(1708963615);
    auto second_round_time = first_round_time + std::chrono::seconds(5);

    mock::timer timer;
    // Four calls within the same second
    timer.time = first_round_time;

    mock::rate_limiter rate_limiter(2);
    rate_limiter.set_timer(timer);

    int allowed = 0;
    for (int i = 0; i < 10; i++) {
        if (rate_limiter.allow()) {
            allowed++;
        }
    }
    EXPECT_EQ(2, allowed);

    timer.time = second_round_time;
    rate_limiter.set_timer(timer);

    allowed = 0;
    for (int i = 0; i < 10; i++) {
        if (rate_limiter.allow()) {
            allowed++;
        }
    }
    EXPECT_EQ(2, allowed);
}

TEST(RateLimitTest, OnlyAllowedMaxPerSecondConsecutiveSeconds)
{
    auto first_round_time = system_clock::duration(1708963615);
    auto second_round_time = first_round_time + std::chrono::seconds(1);

    mock::timer timer;
    // Four calls within the same second
    timer.time = first_round_time;

    mock::rate_limiter rate_limiter(2);
    rate_limiter.set_timer(timer);

    int allowed = 0;
    for (int i = 0; i < 10; i++) {
        if (rate_limiter.allow()) {
            allowed++;
        }
    }
    EXPECT_EQ(2, allowed);

    timer.time = second_round_time;
    rate_limiter.set_timer(timer);

    allowed = 0;
    for (int i = 0; i < 10; i++) {
        if (rate_limiter.allow()) {
            allowed++;
        }
    }
    // It is a bit random
    EXPECT_TRUE(allowed >= 0 && allowed <= 2);
}

TEST(RateLimitTest, WhenNotMaxPerSecondItAlwaysAllow)
{
    dds::rate_limiter<dds::timer> rate_limiter(0);

    EXPECT_TRUE(rate_limiter.allow());
    EXPECT_TRUE(rate_limiter.allow());
    EXPECT_TRUE(rate_limiter.allow());
    EXPECT_TRUE(rate_limiter.allow());
}

} // namespace dds
