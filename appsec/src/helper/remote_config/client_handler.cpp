// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "client_handler.hpp"
#include "../metrics.hpp"
#include "listeners/asm_features_listener.hpp"
#include "listeners/engine_listener.hpp"
#include "listeners/listener.hpp"
#include <atomic>
#include <chrono>

namespace dds::remote_config {

static constexpr std::chrono::milliseconds default_max_interval = 5min;

client_handler::client_handler(std::unique_ptr<client> &&rc_client,
    std::shared_ptr<service_config> service_config,
    std::shared_ptr<metrics::TelemetrySubmitter> msubmitter)
    : rc_client_{std::move(rc_client)},
      service_config_{std::move(service_config)}, msubmitter_{
                                                      std::move(msubmitter)}
{}

std::unique_ptr<client_handler> client_handler::from_settings(
    const dds::engine_settings &eng_settings,
    std::shared_ptr<dds::service_config> service_config,
    const remote_config::settings &rc_settings,
    const std::shared_ptr<engine> &engine_ptr,
    std::shared_ptr<metrics::TelemetrySubmitter> msubmitter,
    bool dynamic_enablement)
{
    if (!rc_settings.enabled) {
        return {};
    }

    if (!service_config) {
        return {};
    }

    std::vector<std::shared_ptr<remote_config::listener_base>> listeners = {};
    if (dynamic_enablement) {
        listeners.emplace_back(
            std::make_shared<remote_config::asm_features_listener>(
                service_config));
    }

    if (eng_settings.rules_file.empty()) {
        listeners.emplace_back(std::make_shared<remote_config::engine_listener>(
            engine_ptr, msubmitter, eng_settings.rules_file_or_default()));
    }

    if (listeners.empty()) {
        SPDLOG_DEBUG(
            "Not enabling remote config for this service as no "
            "listeners are available (no dynamic enablement and no rules "
            "file set)");
        return {};
    }

    auto rc_client =
        remote_config::client::from_settings(rc_settings, std::move(listeners));

    return std::make_unique<client_handler>(
        std::move(rc_client), std::move(service_config), std::move(msubmitter));
}

void client_handler::poll()
{
    try {
        if (last_success_ != empty_time) {
            auto now = std::chrono::steady_clock::now();
            auto elapsed =
                std::chrono::duration_cast<std::chrono::milliseconds>(
                    now - last_success_);
            msubmitter_->submit_metric("remote_config.last_success"sv,
                static_cast<double>(elapsed.count()), {});
        }

        const bool result = rc_client_->poll();

        auto now = std::chrono::steady_clock::now();
        last_success_ = now;

        auto creation_time = creation_time_.load(std::memory_order_acquire);
        if (result && creation_time != empty_time) {
            auto elapsed =
                std::chrono::duration_cast<std::chrono::milliseconds>(
                    now - creation_time);
            msubmitter_->submit_metric("remote_config.first_pull"sv,
                static_cast<double>(elapsed.count()), {});
            creation_time_.store(empty_time, std::memory_order_release);
        }
    } catch (const std::exception &e) {
        SPDLOG_WARN("Error polling remote config: {}", e.what());
    }
}
} // namespace dds::remote_config
