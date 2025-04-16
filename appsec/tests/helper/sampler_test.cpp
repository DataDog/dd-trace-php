// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <atomic>
#include <gmock/gmock-cardinalities.h>
#include <sampler.hpp>
#include <thread>

// Fixture
class TimedSetTest : public ::testing::Test {
protected:
    static inline TimedSetTest *instance;
    void SetUp() override { instance = this; }

    struct MockTimeProvider {
        std::uint32_t now()
        {
            return instance->currentTime.load(std::memory_order_relaxed);
        }
    };

    // Helper to advance the mocked time
    void advanceTime(std::uint32_t units_of_time)
    {
        currentTime.fetch_add(units_of_time, std::memory_order_relaxed);
    }

    static constexpr std::size_t MaxItems = 12;
    static constexpr std::size_t Capacity = 32;
    static constexpr std::uint32_t Threshold = 10;

    std::atomic<std::uint32_t> currentTime = 10;

    testing::NiceMock<MockTimeProvider> mockTimeProvider;
};

TEST_F(TimedSetTest, BasicHitFunctionality)
{
    dds::timed_set<MaxItems, Capacity, MockTimeProvider, Threshold> set;
    auto acc = set.new_accessor();

    EXPECT_TRUE(acc.hit(12345));
    EXPECT_FALSE(acc.hit(12345));

    advanceTime(Threshold);
    EXPECT_TRUE(acc.hit(12345));

    advanceTime(Threshold - 1);
    EXPECT_FALSE(acc.hit(12345));
}

TEST_F(TimedSetTest, MultipleEntries)
{
    dds::timed_set<MaxItems, Capacity, MockTimeProvider, Threshold> set;
    auto acc = set.new_accessor();

    EXPECT_TRUE(acc.hit(1));
    EXPECT_TRUE(acc.hit(2));
    EXPECT_TRUE(acc.hit(3));

    EXPECT_FALSE(acc.hit(1));
    EXPECT_FALSE(acc.hit(2));
    EXPECT_FALSE(acc.hit(3));

    EXPECT_TRUE(acc.hit(4));
}

TEST_F(TimedSetTest, NearCapacityBehavior)
{
    dds::timed_set<MaxItems, Capacity, MockTimeProvider, Threshold> set;
    auto acc = set.new_accessor();

    // put the first quarter behind the threshold
    std::uint64_t i = 1;
    for (; i <= MaxItems / 4; i++) { EXPECT_TRUE(acc.hit(i)); }
    advanceTime(Threshold);

    // the second quarter are behind the threshold, but older
    for (; i <= MaxItems / 2; i++) { EXPECT_TRUE(acc.hit(i)); }
    advanceTime(Threshold / 2);

    // the rest till MaxItems are the newest
    for (; i <= MaxItems; i++) { EXPECT_TRUE(acc.hit(i)); }

    EXPECT_EQ(acc.approx_size(), MaxItems);

    // bow we add one; this should trigger a rebuild
    EXPECT_TRUE(acc.hit(MaxItems + 1));

    // wait until the size falls back to below max items:
    auto deadline = std::chrono::steady_clock::now() + std::chrono::seconds(2);
    while (std::chrono::steady_clock::now() < deadline) {
        if (acc.approx_size() < MaxItems) {
            break;
        }
        std::this_thread::sleep_for(std::chrono::milliseconds(10));
    }
    ASSERT_EQ(acc.approx_size(), MaxItems / 3 * 2);
}

TEST_F(TimedSetTest, ConcurrentAccess)
{
    // Use actual time provider for this test
    dds::timed_set<MaxItems, Capacity, MockTimeProvider, Threshold> set;
    auto acc = set.new_accessor();

    static constexpr int numThreads = 8;

    std::atomic<std::uint64_t> totalHits{};
    std::atomic<bool> stop{};
    std::vector<std::thread> threads;

    for (int t = 0; t < numThreads; ++t) {
        threads.emplace_back([&acc, &totalHits, &stop]() {
            while (!stop.load(std::memory_order_relaxed)) {
                if (acc.hit(1)) {
                    totalHits.fetch_add(1);
                }
                if (acc.hit(2)) {
                    totalHits.fetch_add(1);
                }
                if (acc.hit(3)) {
                    totalHits.fetch_add(1);
                }
            }
        });
    }

    for (int i = 0; i < 25; ++i) {
        advanceTime(Threshold / 2);
        std::this_thread::sleep_for(std::chrono::milliseconds(20));
    }

    stop.store(true, std::memory_order_relaxed);
    for (auto &thread : threads) { thread.join(); }

    EXPECT_EQ(totalHits.load(), 3 * 13);
}
