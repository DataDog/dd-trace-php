// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include "service_config.hpp"
#include <sampler.hpp>
#include <thread>

namespace dds {

namespace mock {

class sampler : public dds::sampler {
public:
    sampler(double sampler_rate) : dds::sampler(sampler_rate) {}
    void set_request(unsigned int i) { request_ = i; }
    unsigned get_request() { return request_; }
};

} // namespace mock

std::atomic<int> picked = 0;

void count_picked(dds::sampler &sampler, int iterations)
{
    for (int i = 0; i < iterations; i++) {
        if (sampler.get()) {
            picked++;
        }
    }
}

TEST(SamplerTest, ItPicksAllWhenRateIs1)
{
    auto service_config = std::make_shared<dds::service_config>();
    service_config->enable_asm();
    sampler s(1);
    picked = 0;
    count_picked(s, 100);

    EXPECT_EQ(100, picked);
}

TEST(SamplerTest, ItPicksNoneWhenRateIs0)
{
    auto service_config = std::make_shared<dds::service_config>();
    service_config->enable_asm();
    sampler s(0);
    picked = 0;
    count_picked(s, 100);

    EXPECT_EQ(0, picked);
}

TEST(SamplerTest, ItPicksHalfWhenPortionGiven)
{
    auto service_config = std::make_shared<dds::service_config>();
    service_config->enable_asm();
    sampler s(0.5);
    picked = 0;
    count_picked(s, 100);

    EXPECT_EQ(50, picked);
}

TEST(SamplerTest, ItResetTokensAfter100Calls)
{
    auto service_config = std::make_shared<dds::service_config>();
    service_config->enable_asm();
    sampler s(1);

    picked = 0;
    count_picked(s, 100);
    count_picked(s, 100);

    EXPECT_EQ(200, picked);
}

TEST(SamplerTest, ItWorksWithDifferentMagnitudes)
{
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        sampler s(0.1);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(1, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        sampler s(0.5);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(5, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        sampler s(0.01);
        picked = 0;
        count_picked(s, 100);

        EXPECT_EQ(1, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        sampler s(0.02);
        picked = 0;
        count_picked(s, 100);

        EXPECT_EQ(2, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        sampler s(0.001);
        picked = 0;
        count_picked(s, 1000);

        EXPECT_EQ(1, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        sampler s(0.003);
        picked = 0;
        count_picked(s, 1000);

        EXPECT_EQ(3, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        sampler s(0.0001);
        picked = 0;
        count_picked(s, 10000);

        EXPECT_EQ(1, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        sampler s(0.0007);
        picked = 0;
        count_picked(s, 10000);

        EXPECT_EQ(7, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        sampler s(0.123);
        picked = 0;
        count_picked(s, 1000);

        EXPECT_EQ(123, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
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
    EXPECT_EQ(0, s.get_request());
}
} // namespace dds
