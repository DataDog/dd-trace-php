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
    auto timer = std::make_unique<mock::timer>();
    ;
    // Four calls within the same second
    timer->responses.push(system_clock::duration(1708963615));
    timer->responses.push(system_clock::duration(1708963615));
    timer->responses.push(system_clock::duration(1708963615));
    timer->responses.push(system_clock::duration(1708963615));
    // Four extra calls on next second
    timer->responses.push(system_clock::duration(1709963630));
    timer->responses.push(system_clock::duration(1709963630));
    timer->responses.push(system_clock::duration(1709963630));
    timer->responses.push(system_clock::duration(1709963630));

    mock::rate_limiter rate_limiter(2);
    rate_limiter.set_timer(std::move(timer));

    EXPECT_TRUE(rate_limiter.allow());
    EXPECT_TRUE(rate_limiter.allow());
    EXPECT_FALSE(rate_limiter.allow());
    EXPECT_FALSE(rate_limiter.allow());
    EXPECT_TRUE(rate_limiter.allow());
    EXPECT_TRUE(rate_limiter.allow());
    EXPECT_FALSE(rate_limiter.allow());
    EXPECT_FALSE(rate_limiter.allow());
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
