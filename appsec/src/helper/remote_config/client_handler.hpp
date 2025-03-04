// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../engine.hpp"
#include "../service_config.hpp"
#include "client.hpp"
#include "settings.hpp"
#include <atomic>
#include <memory>
#include <mutex>

namespace dds::remote_config {

using namespace std::chrono_literals;

class client_handler {
public:
    client_handler(std::unique_ptr<remote_config::client> &&rc_client,
        std::shared_ptr<service_config> service_config,
        std::shared_ptr<metrics::telemetry_submitter> msubmitter);
    ~client_handler() = default;

    client_handler(const client_handler &) = delete;
    client_handler &operator=(const client_handler &) = delete;

    client_handler(client_handler &&) = delete;
    client_handler &operator=(client_handler &&) = delete;

    static std::unique_ptr<client_handler> from_settings(
        const dds::engine_settings &eng_settings,
        std::shared_ptr<dds::service_config> service_config,
        const remote_config::settings &rc_settings,
        const std::shared_ptr<engine> &engine_ptr,
        std::shared_ptr<metrics::telemetry_submitter> msubmitter,
        bool /* dynamic_enablement */);

    void poll();

    bool has_applied_rc()
    {
        std::lock_guard lock{mutex_};
        return creation_time_ == empty_time;
    }

protected:
    static constexpr auto empty_time = std::chrono::steady_clock::time_point{};

    std::shared_ptr<service_config> service_config_;
    std::unique_ptr<remote_config::client> rc_client_;
    std::shared_ptr<metrics::telemetry_submitter> msubmitter_;

    std::mutex mutex_{};
    std::chrono::steady_clock::time_point creation_time_{
        std::chrono::steady_clock::now()}; // def value after first poll() done
};

} // namespace dds::remote_config
