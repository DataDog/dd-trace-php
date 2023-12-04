// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include "remote_config/mocks.hpp"
#include <remote_config/client.hpp>
#include <service.hpp>
#include <stdexcept>

namespace dds {

TEST(ServiceTest, NullEngine)
{
    service_identifier sid{"service", {"extra01", "extra02"}, "env",
        "tracer_version", "app_version", "runtime_id"};
    std::shared_ptr<engine> engine;
    auto client = std::make_unique<remote_config::mock::client>(sid);
    EXPECT_CALL(*client, poll).Times(0);

    auto service_config = std::make_shared<dds::service_config>();
    auto client_handler = std::make_shared<remote_config::client_handler>(
        std::move(client), service_config, 1s);

    EXPECT_THROW(
        auto s = service(engine, service_config, std::move(client_handler)),
        std::runtime_error);
}

TEST(ServiceTest, NullServiceHandler)
{
    std::shared_ptr<engine> engine{engine::create()};
    auto service_config = std::make_shared<dds::service_config>();

    // A null service handler doesn't make a difference as remote config is
    // optional
    service svc{engine, service_config, nullptr};
    EXPECT_EQ(engine.get(), svc.get_engine().get());
}

TEST(ServiceTest, ServicePickSchemaExtractionSamples)
{
    std::shared_ptr<engine> engine{engine::create()};

    service_identifier sid{"service", {"extra01", "extra02"}, "env",
        "tracer_version", "app_version", "runtime_id"};
    auto client = std::make_unique<remote_config::mock::client>(sid);
    auto service_config = std::make_shared<dds::service_config>();
    engine_settings engine_settings = {};
    engine_settings.rules_file = create_sample_rules_ok();
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    { // Constructor. It picks based on rate
        double all_requests_are_picked = 1.0;
        auto s = service(
            engine, service_config, nullptr, {true, all_requests_are_picked});

        EXPECT_TRUE(s.get_schema_sampler()->get().has_value());
    }

    { // Constructor. It does not pick based on rate
        double no_request_is_picked = 0.0;
        auto s = service(
            engine, service_config, nullptr, {true, no_request_is_picked});

        EXPECT_FALSE(s.get_schema_sampler()->get().has_value());
    }

    { // Constructor. It does not pick if disabled
        double all_requests_are_picked = 1.0;
        bool schema_extraction_disabled = false;
        auto s = service(engine, service_config, nullptr,
            {schema_extraction_disabled, all_requests_are_picked});

        EXPECT_FALSE(s.get_schema_sampler()->get().has_value());
    }

    { // Static constructor. It picks based on rate
        engine_settings.schema_extraction.enabled = true;
        engine_settings.schema_extraction.sample_rate = 1.0;
        auto service = service::from_settings(
            service_identifier(sid), engine_settings, {}, meta, metrics, false);

        EXPECT_TRUE(service->get_schema_sampler()->get().has_value());
    }

    { // Static constructor.  It does not pick based on rate
        engine_settings.schema_extraction.enabled = true;
        engine_settings.schema_extraction.sample_rate = 0.0;
        auto service = service::from_settings(
            service_identifier(sid), engine_settings, {}, meta, metrics, false);

        EXPECT_FALSE(service->get_schema_sampler()->get().has_value());
    }

    { // Static constructor. It does not pick if disabled
        engine_settings.schema_extraction.enabled = false;
        engine_settings.schema_extraction.sample_rate = 1.0;
        auto service = service::from_settings(
            service_identifier(sid), engine_settings, {}, meta, metrics, false);

        EXPECT_FALSE(service->get_schema_sampler()->get().has_value());
    }
}

} // namespace dds
