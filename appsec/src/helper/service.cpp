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
    std::shared_ptr<metrics_impl> msubmitter, std::string rc_path,
    const schema_extraction_settings &schema_extraction_settings)
    : engine_{std::move(engine)}, service_config_{std::move(service_config)},
      client_handler_{std::move(client_handler)},
      msubmitter_{std::move(msubmitter)},
      schema_extraction_enabled_{schema_extraction_settings.enabled},
      schema_sampler_{
          schema_extraction_settings.enabled &&
                  schema_extraction_settings.sampling_period >= 1.0
              ? std::make_optional<sampler>(static_cast<std::uint32_t>(
                    schema_extraction_settings.sampling_period))
              : std::nullopt},
      rc_path_{std::move(rc_path)}
{
    // The engine should always be valid
    if (!engine_) {
        throw std::runtime_error("invalid engine");
    }

    if (client_handler_) {
        client_handler_->poll();
    }
}

std::shared_ptr<service> service::from_settings(
    const dds::engine_settings &eng_settings,
    const remote_config::settings &rc_settings)
{
    std::shared_ptr<metrics_impl> msubmitter = std::make_shared<metrics_impl>();

    const std::shared_ptr<engine> engine_ptr =
        engine::from_settings(eng_settings, *msubmitter);

    auto service_config = std::make_shared<dds::service_config>();

    auto client_handler = remote_config::client_handler::from_settings(
        eng_settings, service_config, rc_settings, engine_ptr, msubmitter);

    return create_shared(engine_ptr, std::move(service_config),
        std::move(client_handler), std::move(msubmitter),
        rc_settings.shmem_path, eng_settings.schema_extraction);
}
} // namespace dds
