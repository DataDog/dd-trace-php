// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include "engine_settings.hpp"
#include <boost/algorithm/string/predicate.hpp>
#include <metrics.hpp>
#include <service_manager.hpp>

namespace algo = boost::algorithm;

namespace dds {

TEST(ServiceManagerTest, DefaultRulesFile)
{
    auto path = engine_settings::default_rules_file();
    EXPECT_TRUE(algo::ends_with(path, "/etc/dd-appsec/recommended.json"));
}

struct service_manager_exp : public service_manager {
    auto &get_cache() { return cache_; }
};

TEST(ServiceManagerTest, LoadRulesOK)
{
    service_manager_exp manager;
    auto fn = create_sample_rules_ok();
    dds::engine_settings engine_settings;
    engine_settings.rules_file = fn;
    engine_settings.waf_timeout_us = 42;
    auto service = manager.create_service(
        {"service", {}, "env", "", "", ""}, engine_settings, {}, {});
    auto metrics = service->drain_legacy_metrics();
    EXPECT_EQ(manager.get_cache().size(), 1);
    EXPECT_EQ(metrics[metrics::event_rules_loaded], 4);

    // loading again should take from the cache
    auto service2 = manager.create_service(
        {"service", {}, "env", "", "", ""}, engine_settings, {}, {});
    EXPECT_EQ(manager.get_cache().size(), 1);

    // Even with different extra services, it should get the same
    auto service3 = manager.create_service(
        {"service", {"some", "services"}, "env", "", "", ""}, engine_settings,
        {}, {});
    EXPECT_EQ(manager.get_cache().size(), 1);

    // destroying the services should expire the cache ptr
    auto cache_it = manager.get_cache().begin();
    ASSERT_NE(cache_it, manager.get_cache().end());

    std::weak_ptr<dds::service> weak_ptr = cache_it->second;
    ASSERT_FALSE(weak_ptr.expired());
    service.reset();
    ASSERT_FALSE(weak_ptr.expired());
    service2.reset();
    ASSERT_FALSE(weak_ptr.expired());
    service3.reset();
    // the last one should be kept by the manager
    ASSERT_FALSE(weak_ptr.expired());

    // loading another service should cleanup the cache
    auto service4 = manager.create_service(
        {"service2", {}, "env"}, engine_settings, {}, {});
    ASSERT_TRUE(weak_ptr.expired());
    EXPECT_EQ(manager.get_cache().size(), 1);

    // another service identifier should result in another service
    auto service5 = manager.create_service(
        {"service", {}, "env", "", "", ""}, engine_settings, {}, {});
    EXPECT_EQ(manager.get_cache().size(), 2);
}

TEST(ServiceManagerTest, LoadRulesFileNotFound)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    service_manager_exp manager;
    EXPECT_THROW(
        {
            dds::engine_settings engine_settings;
            engine_settings.rules_file = "/file/that/does/not/exist";
            engine_settings.waf_timeout_us = 42;
            manager.create_service({"s", {}, "e"}, engine_settings, {}, {});
        },
        std::runtime_error);
}

TEST(ServiceManagerTest, BadRulesFile)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    service_manager_exp manager;
    EXPECT_THROW(
        {
            dds::engine_settings engine_settings;
            engine_settings.rules_file = "/dev/null";
            engine_settings.waf_timeout_us = 42;
            manager.create_service({"s", {}, "e"}, engine_settings, {}, {});
        },
        dds::parsing_error);
}

TEST(ServiceManagerTest, UniqueServices)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    service_manager_exp manager;
    auto fn = create_sample_rules_ok();
    dds::engine_settings engine_settings;
    engine_settings.rules_file = fn;

    auto service1 = manager.create_service(
        {"service", {}, "env", "1.0", "2.0", "runtime ID 0"}, engine_settings,
        {}, {});
    auto service2 = manager.create_service(
        {"service", {}, "env", "1.1", "3.0", "runtime ID 1"}, engine_settings,
        {}, {});

    EXPECT_EQ(service1.get(), service2.get());
}
} // namespace dds
