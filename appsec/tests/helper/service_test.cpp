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

TEST(ServiceTest, ServicePickSchemaExtractionSamples)
{
    std::shared_ptr<engine> engine{engine::create()};

    auto create_engine_settings = [](bool enabled, double sample_rate) {
        engine_settings engine_settings = {};
        engine_settings.rules_file = create_sample_rules_ok();
        engine_settings.schema_extraction.enabled = enabled;
        engine_settings.schema_extraction.sample_rate = sample_rate;
        return engine_settings;
    };
    auto service_config = std::make_shared<dds::service_config>();

    { // All requests are picked
        auto s = service::from_settings(
            create_engine_settings(true, 1.0), {}, false);

        EXPECT_TRUE(s->get_schema_sampler()->picked());
    }

    { // Static constructor. It does not pick based on rate
        auto s = service::from_settings(
            create_engine_settings(true, 0.0), {}, false);

        EXPECT_FALSE(s->get_schema_sampler()->picked());
    }

    { // Static constructor. It does not pick if disabled
        auto s = service::from_settings(
            create_engine_settings(false, 1.0), {}, false);

        EXPECT_FALSE(s->get_schema_sampler()->picked());
    }
}

} // namespace dds
