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
    sampler(std::shared_ptr<service_config> service_config)
        : dds::sampler(service_config)
    {}
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
    auto service_config = std::make_shared<dds::service_config>();
    service_config->enable_asm();
    service_config->set_request_sample_rate(1);
    sampler s(service_config);
    picked = 0;
    count_picked(s, 100);

    EXPECT_EQ(100, picked);
}

TEST(SamplerTest, ItPicksNoneWhenRateIs0)
{
    auto service_config = std::make_shared<dds::service_config>();
    service_config->enable_asm();
    service_config->set_request_sample_rate(0);
    sampler s(service_config);
    picked = 0;
    count_picked(s, 100);

    EXPECT_EQ(0, picked);
}

TEST(SamplerTest, ItPicksHalfWhenPortionGiven)
{
    auto service_config = std::make_shared<dds::service_config>();
    service_config->enable_asm();
    service_config->set_request_sample_rate(0.5);
    sampler s(service_config);
    picked = 0;
    count_picked(s, 100);

    EXPECT_EQ(50, picked);
}

TEST(SamplerTest, ItResetTokensAfter100Calls)
{
    auto service_config = std::make_shared<dds::service_config>();
    service_config->enable_asm();
    service_config->set_request_sample_rate(1);
    sampler s(service_config);

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
        service_config->set_request_sample_rate(0.1);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(1, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.5);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(5, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.01);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 100);

        EXPECT_EQ(1, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.02);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 100);

        EXPECT_EQ(2, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.001);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 1000);

        EXPECT_EQ(1, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.003);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 1000);

        EXPECT_EQ(3, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.0001);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 10000);

        EXPECT_EQ(1, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.0007);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 10000);

        EXPECT_EQ(7, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.123);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 1000);

        EXPECT_EQ(123, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.6);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(6, picked);
    }
}

TEST(SamplerTest, TestInvalidSampleRatesDefaultToTenPercent)
{
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(2);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(10, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(-1);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(0, picked);
    }
    { // Below limit goes to default 10 percent
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.000001);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 1000000);

        EXPECT_EQ(100, picked);
    }
}

TEST(SamplerTest, TestLimits)
{
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(0, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(1);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 10);

        EXPECT_EQ(10, picked);
    }
    {
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.0001);
        sampler s(service_config);
        picked = 0;
        count_picked(s, 10000);

        EXPECT_EQ(1, picked);
    }
}

TEST(SamplerTest, TestOverflow)
{
    auto service_config = std::make_shared<dds::service_config>();
    service_config->enable_asm();
    service_config->set_request_sample_rate(0);
    mock::sampler s(service_config);
    s.set_request(UINT_MAX);
    s.get();
    EXPECT_EQ(1, s.get_request());
}

TEST(SamplerTest, ModifySamplerRate)
{
    { // New sampler rate reset requests
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.1);
        mock::sampler s(service_config);
        s.get();
        EXPECT_EQ(2, s.get_request());
        service_config->set_request_sample_rate(0.2);
        s.get();
        EXPECT_EQ(2, s.get_request());
    }
    { // Setting same sampler rate does do anything
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.1);
        mock::sampler s(service_config);
        s.get();
        EXPECT_EQ(2, s.get_request());
        service_config->set_request_sample_rate(0.1);
        s.get();
        EXPECT_EQ(3, s.get_request());
    }
    { // Over Zero: If given rate is invalid and gets defaulted to a value which
      // is same as before, it does not change anything
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(3);
        mock::sampler s(service_config);
        s.get();
        EXPECT_EQ(2, s.get_request());
        service_config->set_request_sample_rate(4);
        s.get();
        EXPECT_EQ(3, s.get_request());
    }
    { // Below zero: If given rate is invalid and gets defaulted to a value
      // which is same as before, it does not change anything
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(-3);
        mock::sampler s(service_config);
        s.get();
        EXPECT_EQ(2, s.get_request());
        service_config->set_request_sample_rate(-4);
        s.get();
        EXPECT_EQ(3, s.get_request());
    }
    { // Below min: If given rate is invalid and gets defaulted to a value which
      // is same as before, it does not change anything
        auto service_config = std::make_shared<dds::service_config>();
        service_config->enable_asm();
        service_config->set_request_sample_rate(0.000001);
        mock::sampler s(service_config);
        s.get();
        EXPECT_EQ(2, s.get_request());
        service_config->set_request_sample_rate(0.000002);
        s.get();
        EXPECT_EQ(3, s.get_request());
    }
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
    auto service_config = std::make_shared<dds::service_config>();
    service_config->enable_asm();
    service_config->set_request_sample_rate(1);
    sampler sampler(service_config);
    auto is_pick = sampler.get();
    EXPECT_TRUE(is_pick != std::nullopt);
    is_pick = sampler.get();
    EXPECT_FALSE(is_pick != std::nullopt);
    is_pick.reset();
    is_pick = sampler.get();
    EXPECT_TRUE(is_pick != std::nullopt);
}
} // namespace dds
