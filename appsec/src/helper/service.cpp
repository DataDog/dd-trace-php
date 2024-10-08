// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "service.hpp"

namespace dds {

service::service(std::shared_ptr<engine> engine,
    std::shared_ptr<service_config> service_config,
    std::unique_ptr<dds::remote_config::client_handler> &&client_handler,
    std::string rc_path,
    const schema_extraction_settings &schema_extraction_settings)
    : engine_{std::move(engine)}, service_config_{std::move(service_config)},
      client_handler_{std::move(client_handler)}, rc_path_{std::move(rc_path)}
{
    // The engine should always be valid
    if (!engine_) {
        throw std::runtime_error("invalid engine");
    }

    double sample_rate = schema_extraction_settings.sample_rate;

    if (!schema_extraction_settings.enabled) {
        sample_rate = 0;
    }

    schema_sampler_ = std::make_shared<sampler>(sample_rate);

    if (client_handler_) {
        client_handler_->poll();
    }
}

std::shared_ptr<service> service::from_settings(
    const dds::engine_settings &eng_settings,
    const remote_config::settings &rc_settings,
    std::map<std::string, std::string> &meta,
    std::map<std::string_view, double> &metrics, bool dynamic_enablement)
{
    const std::shared_ptr<engine> engine_ptr =
        engine::from_settings(eng_settings, meta, metrics);

    auto service_config = std::make_shared<dds::service_config>();

    auto client_handler =
        remote_config::client_handler::from_settings(eng_settings,
            service_config, rc_settings, engine_ptr, dynamic_enablement);

    return std::make_shared<service>(engine_ptr, std::move(service_config),
        std::move(client_handler), rc_settings.shmem_path,
        eng_settings.schema_extraction);
}
} // namespace dds
