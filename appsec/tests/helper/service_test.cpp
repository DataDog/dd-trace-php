// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include "remote_config/mocks.hpp"
#include "remote_config/settings.hpp"
#include <remote_config/client.hpp>
#include <service.hpp>
#include <stdexcept>

extern "C" {
struct ddog_CharSlice {
    const char *ptr;
    uintptr_t len;
};
struct ddog_RemoteConfigReader {
    std::string shm_path;
    struct ddog_CharSlice next_line;
};
__attribute__((visibility("default"))) ddog_RemoteConfigReader *
ddog_remote_config_reader_for_path(const char *path)
{
    return new ddog_RemoteConfigReader{path};
}
__attribute__((visibility("default"))) bool ddog_remote_config_read(
    ddog_RemoteConfigReader *reader, ddog_CharSlice *data)
{
    if (reader->next_line.len == 0) {
        return false;
    }
    data->ptr = reader->next_line.ptr;
    data->len = reader->next_line.len;
    reader->next_line.len = 0;
    return true;
}
__attribute__((visibility("default"))) void ddog_remote_config_reader_drop(
    struct ddog_RemoteConfigReader *reader)
{
    delete reader;
}

__attribute__((constructor)) void resolve_symbols()
{
    dds::remote_config::resolve_symbols();
}
}

namespace dds {

TEST(ServiceTest, NullEngine)
{
    std::shared_ptr<engine> engine{};
    remote_config::settings rc_settings{true, ""};
    auto client = remote_config::client::from_settings(rc_settings, {});

    auto service_config = std::make_shared<dds::service_config>();
    auto client_handler = std::make_unique<remote_config::client_handler>(
        std::move(client), service_config);

    EXPECT_THROW(
        auto s = service(engine, service_config, std::move(client_handler), ""),
        std::runtime_error);
}

TEST(ServiceTest, NullServiceHandler)
{
    std::shared_ptr<engine> engine{engine::create()};
    auto service_config = std::make_shared<dds::service_config>();

    // A null service handler doesn't make a difference as remote config is
    // optional
    service svc{engine, service_config, nullptr, ""};
    EXPECT_EQ(engine.get(), svc.get_engine().get());
}

TEST(ServiceTest, ServicePickSchemaExtractionSamples)
{
    std::shared_ptr<engine> engine{engine::create()};

    auto client = remote_config::client::from_settings({true}, {});
    auto service_config = std::make_shared<dds::service_config>();
    engine_settings engine_settings = {};
    engine_settings.rules_file = create_sample_rules_ok();
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    { // Constructor. It picks based on rate
        double all_requests_are_picked = 1.0;
        auto s = service(engine, service_config, nullptr, "",
            {true, all_requests_are_picked});

        EXPECT_TRUE(s.get_schema_sampler()->picked());
    }

    { // Constructor. It does not pick based on rate
        double no_request_is_picked = 0.0;
        auto s = service(
            engine, service_config, nullptr, "", {true, no_request_is_picked});

        EXPECT_FALSE(s.get_schema_sampler()->picked());
    }

    { // Constructor. It does not pick if disabled
        double all_requests_are_picked = 1.0;
        bool schema_extraction_disabled = false;
        auto s = service(engine, service_config, nullptr, "",
            {schema_extraction_disabled, all_requests_are_picked});

        EXPECT_FALSE(s.get_schema_sampler()->picked());
    }

    { // Static constructor. It picks based on rate
        engine_settings.schema_extraction.enabled = true;
        engine_settings.schema_extraction.sample_rate = 1.0;
        auto service =
            service::from_settings(engine_settings, {}, meta, metrics, false);

        EXPECT_TRUE(service->get_schema_sampler()->picked());
    }

    { // Static constructor.  It does not pick based on rate
        engine_settings.schema_extraction.enabled = true;
        engine_settings.schema_extraction.sample_rate = 0.0;
        auto service =
            service::from_settings(engine_settings, {}, meta, metrics, false);

        EXPECT_FALSE(service->get_schema_sampler()->picked());
    }

    { // Static constructor. It does not pick if disabled
        engine_settings.schema_extraction.enabled = false;
        engine_settings.schema_extraction.sample_rate = 1.0;
        auto service =
            service::from_settings(engine_settings, {}, meta, metrics, false);

        EXPECT_FALSE(service->get_schema_sampler()->picked());
    }
}

} // namespace dds
