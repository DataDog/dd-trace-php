// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include "remote_config/mocks.hpp"
#include "service_identifier.hpp"
#include <remote_config/client.hpp>
#include <service.hpp>
#include <stdexcept>

namespace dds {

TEST(ServiceTest, ServicePickSchemaExtractionSamples)
{
    std::shared_ptr<engine> engine{engine::create()};

    auto create_sid = [] {
        return service_identifier{"service", {"extra01", "extra02"}, "env",
            "tracer_version", "app_version", "runtime_id"};
    };
    auto create_engine_settings = [](bool enabled, double sample_rate) {
        engine_settings engine_settings = {};
        engine_settings.rules_file = create_sample_rules_ok();
        engine_settings.schema_extraction.enabled = enabled;
        engine_settings.schema_extraction.sample_rate = sample_rate;
        return engine_settings;
    };
    auto client = std::make_unique<remote_config::mock::client>(create_sid());
    auto service_config = std::make_shared<dds::service_config>();

    { // All requests are picked
        auto s = service::from_settings(
            create_sid(), create_engine_settings(true, 1.0), {}, false);

        EXPECT_TRUE(s->get_schema_sampler()->get().has_value());
    }

    { // Static constructor. It does not pick based on rate
        auto s = service::from_settings(
            create_sid(), create_engine_settings(true, 0.0), {}, false);

        EXPECT_FALSE(s->get_schema_sampler()->get().has_value());
    }

    { // Static constructor. It does not pick if disabled
        auto s = service::from_settings(
            create_sid(), create_engine_settings(false, 1.0), {}, false);

        EXPECT_FALSE(s->get_schema_sampler()->get().has_value());
    }
}

} // namespace dds
