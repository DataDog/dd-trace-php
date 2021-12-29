// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "client_settings.hpp"
#include "common.hpp"
#include <boost/algorithm/string/predicate.hpp>
#include <engine_pool.hpp>

namespace algo = boost::algorithm;

namespace dds {

TEST(EnginePoolTest, DefaultRulesFile)
{
    auto path = client_settings::default_rules_file();
    EXPECT_TRUE(algo::ends_with(path, "/etc/dd-appsec/recommended.json"));
}

struct engine_pool_exp : public engine_pool {
    auto &get_cache() { return cache_; }
};

TEST(EnginePoolTest, LoadRulesOK)
{
    engine_pool_exp pool;
    auto fn = create_sample_rules_ok();
    auto engine = pool.create_engine({fn, 42});
    EXPECT_EQ(pool.get_cache().size(), 1);

    // loading again should take from the cache
    auto engine2 = pool.create_engine({fn, 42});
    EXPECT_EQ(pool.get_cache().size(), 1);

    // destroying the engines should expire the cache ptr
    auto cache_it = pool.get_cache().begin();
    ASSERT_NE(cache_it, pool.get_cache().end());

    std::weak_ptr<dds::engine> weak_ptr = cache_it->second;
    ASSERT_FALSE(weak_ptr.expired());
    engine.reset();
    ASSERT_FALSE(weak_ptr.expired());
    engine2.reset();
    // the last one is always kept
    ASSERT_FALSE(weak_ptr.expired());

    // loading another file should cleanup the cache
    fn = create_sample_rules_ok();
    auto engine3 = pool.create_engine({fn, 42});
    ASSERT_TRUE(weak_ptr.expired());
    EXPECT_EQ(pool.get_cache().size(), 1);

    // another timeout should result in another engine
    auto engine4 = pool.create_engine({fn, 24});
    EXPECT_EQ(pool.get_cache().size(), 2);
}

TEST(EnginePoolTest, LoadRulesFileNotFound)
{
    engine_pool_exp pool;
    EXPECT_THROW(
        {
            pool.create_engine({"/file/that/does/not/exist", 42});
        },
        std::runtime_error);
}
TEST(EnginePoolTest, BadRulesFile)
{
    engine_pool_exp pool;
    EXPECT_THROW(
        {
            pool.create_engine({"/dev/null", 42});
        },
        dds::parsing_error);
}
} // namespace dds
