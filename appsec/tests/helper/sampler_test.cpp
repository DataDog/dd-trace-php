// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <sampler.hpp>
#include <thread>

namespace dds {

namespace mock {

class sampler : public dds::sampler {
public:
    sampler(double sample_rate) : dds::sampler(sample_rate) {}
    void set_request(unsigned int i) { request_ = i; }
    auto get_request() { return request_; }
};

} // namespace mock

std::atomic<int> picked = 0;

void count_picked(dds::sampler &sampler, int iterations)
{
    for (int i = 0; i < iterations; i++) {
        auto is_pick = sampler.get();
        if (is_pick != std::nullopt) {
            picked++;
        }
    }
}

TEST(SamplerTest, ItPicksAllWhenRateIs1)
{
    sampler s(1);
    picked = 0;
    count_picked(s, 100);

    EXPECT_EQ(100, picked);
}

TEST(SamplerTest, ItPicksNoneWhenRateIs0)
{
    sampler s(0);
    picked = 0;
    count_picked(s, 100);

    EXPECT_EQ(0, picked);
}

TEST(SamplerTest, ItPicksHalfWhenPortionGiven)
{
    sampler s(0.5);
    picked = 0;
    count_picked(s, 100);

    EXPECT_EQ(50, picked);
}

TEST(SamplerTest, ItResetTokensAfter100Calls)
{
    sampler s(1);

    picked = 0;
    count_picked(s, 100);
    count_picked(s, 100);

    EXPECT_EQ(200, picked);
}

TEST(SamplerTest, ItWorksWithDifferentMagnitudes)
{
    {
        sampler s(0.1);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(1, picked);
    }
    {
        sampler s(0.5);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(5, picked);
    }
    {
        sampler s(0.01);
        picked = 0;
        count_picked(s, 100);

        EXPECT_EQ(1, picked);
    }
    {
        sampler s(0.02);
        picked = 0;
        count_picked(s, 100);

        EXPECT_EQ(2, picked);
    }
    {
        sampler s(0.001);
        picked = 0;
        count_picked(s, 1000);

        EXPECT_EQ(1, picked);
    }
    {
        sampler s(0.003);
        picked = 0;
        count_picked(s, 1000);

        EXPECT_EQ(3, picked);
    }
    {
        sampler s(0.0001);
        picked = 0;
        count_picked(s, 10000);

        EXPECT_EQ(1, picked);
    }
    {
        sampler s(0.0007);
        picked = 0;
        count_picked(s, 10000);

        EXPECT_EQ(7, picked);
    }
    {
        sampler s(0.123);
        picked = 0;
        count_picked(s, 1000);

        EXPECT_EQ(123, picked);
    }
    {
        sampler s(0.6);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(6, picked);
    }
}

TEST(SamplerTest, TestInvalidSampleRatesDefaultToTenPercent)
{
    {
        sampler s(2);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(10, picked);
    }
    {
        sampler s(-1);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(0, picked);
    }
    { // Below limit goes to default 10 percent
        sampler s(0.000001);
        picked = 0;
        count_picked(s, 1000000);

        EXPECT_EQ(100, picked);
    }
}

TEST(SamplerTest, TestLimits)
{
    {
        sampler s(0);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(0, picked);
    }
    {
        sampler s(1);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(10, picked);
    }
    {
        sampler s(0.0001);
        picked = 0;
        count_picked(s, 10000);

        EXPECT_EQ(1, picked);
    }
}

TEST(SamplerTest, TestOverflow)
{
    mock::sampler s(0);
    s.set_request(UINT_MAX);
    s.get();
    EXPECT_EQ(1, s.get_request());
}

TEST(ScopeTest, TestConcurrent)
{
    std::atomic<bool> concurrent = false;
    {
        auto s = sampler::scope(std::ref(concurrent));
        EXPECT_TRUE(concurrent);
    }
    EXPECT_FALSE(concurrent);
}

TEST(ScopeTest, TestItDoesNotPickTokenUntilScopeReleased)
{
    sampler sampler(1);
    auto is_pick = sampler.get();
    EXPECT_TRUE(is_pick != std::nullopt);
    is_pick = sampler.get();
    EXPECT_FALSE(is_pick != std::nullopt);
    is_pick.reset();
    is_pick = sampler.get();
    EXPECT_TRUE(is_pick != std::nullopt);
}
} // namespace dds
