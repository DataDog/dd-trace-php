// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "engine.hpp"
#include "metrics.hpp"
#include "remote_config/client.hpp"
#include "remote_config/settings.hpp"
#include "service_config.hpp"
#include "service_identifier.hpp"
#include "std_logging.hpp"
#include "utils.hpp"
#include <future>
#include <memory>
#include <spdlog/spdlog.h>
#include <unordered_map>

namespace dds::remote_config {

using namespace std::chrono_literals;

class client_handler {
public:
    using ptr = std::shared_ptr<client_handler>;

    client_handler(remote_config::client::ptr &&rc_client,
        std::shared_ptr<service_config> service_config,
        const std::chrono::milliseconds &poll_interval = 1s);
    ~client_handler();

    client_handler(const client_handler &) = delete;
    client_handler &operator=(const client_handler &) = delete;

    client_handler(client_handler &&) = delete;
    client_handler &operator=(client_handler &&) = delete;

    static client_handler::ptr from_settings(service_identifier &&id,
        const dds::engine_settings &eng_settings,
        std::shared_ptr<dds::service_config> service_config,
        const remote_config::settings &rc_settings,
        const engine::ptr &engine_ptr,
        std::shared_ptr<metrics::TelemetrySubmitter> msubmitter,
        bool dynamic_enablement);

    bool start();

    remote_config::client *get_client() { return rc_client_.get(); }

    void register_runtime_id(const std::string &id)
    {
        if (rc_client_) {
            rc_client_->register_runtime_id(id);
        }
    }
    void unregister_runtime_id(const std::string &id)
    {
        if (rc_client_) {
            rc_client_->unregister_runtime_id(id);
        }
    }

protected:
    void run(std::future<bool> &&exit_signal);
    void handle_error();

    remote_config::client::ptr rc_client_;
    std::shared_ptr<service_config> service_config_;

    std::chrono::milliseconds poll_interval_;
    std::chrono::milliseconds interval_;
    std::chrono::milliseconds max_interval;
    void poll();
    void discover();
    void tick();
    std::function<void()> rc_action_;

    std::uint16_t errors_ = {0};

    std::promise<bool> exit_;
    std::thread handler_;
};

} // namespace dds::remote_config
