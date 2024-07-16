// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "client_handler.hpp"
#include "listeners/asm_features_listener.hpp"
#include "listeners/engine_listener.hpp"
#include "listeners/listener.hpp"
#include "metrics.hpp"
#include <atomic>
#include <chrono>

namespace dds::remote_config {

static constexpr std::chrono::milliseconds default_max_interval = 5min;

client_handler::client_handler(remote_config::client::ptr &&rc_client,
    std::shared_ptr<service_config> service_config,
    std::shared_ptr<metrics::TelemetrySubmitter> msubmitter,
    const std::chrono::milliseconds &poll_interval)
    : service_config_(std::move(service_config)),
      rc_client_(std::move(rc_client)), poll_interval_(poll_interval),
      msubmitter_(std::move(msubmitter)), interval_(poll_interval),
      max_interval(default_max_interval)
{
    // It starts checking if rc is available
    rc_action_ = [this] { discover(); };
}

client_handler::~client_handler()
{
    if (handler_.joinable()) {
        exit_.set_value(true);
        handler_.join();
    }
}

client_handler::ptr client_handler::from_settings(service_identifier &&id,
    const dds::engine_settings &eng_settings,
    std::shared_ptr<dds::service_config> service_config,
    const remote_config::settings &rc_settings, const engine::ptr &engine_ptr,
    std::shared_ptr<metrics::TelemetrySubmitter> msubmitter,
    bool dynamic_enablement)
{
    if (!rc_settings.enabled) {
        return {};
    }

    if (!service_config) {
        return {};
    }

    std::vector<remote_config::listener_base::shared_ptr> listeners = {};
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
        return {};
    }

    auto rc_client = remote_config::client::from_settings(std::move(id),
        remote_config::settings(rc_settings), std::move(listeners));

    return std::make_shared<client_handler>(std::move(rc_client),
        std::move(service_config), std::move(msubmitter),
        std::chrono::milliseconds{rc_settings.poll_interval});
}

bool client_handler::start()
{
    if (rc_client_) {
        handler_ = std::thread(&client_handler::run, this, exit_.get_future());
        return true;
    }

    return false;
}

void client_handler::handle_error()
{
    rc_action_ = [this] { discover(); };

    if (errors_ < std::numeric_limits<std::uint16_t>::max() - 1) {
        errors_++;
    }

    if (interval_ < max_interval) {
        auto new_interval =
            std::chrono::duration_cast<std::chrono::milliseconds>(
                poll_interval_ * pow(2, errors_));
        interval_ = std::min(max_interval, new_interval);
    }
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
    } catch (dds::remote_config::network_exception & /** e */) {
        handle_error();
    }
}
void client_handler::discover()
{
    try {
        if (rc_client_->is_remote_config_available()) {
            // Remote config is available. Start polls
            rc_action_ = [this] { poll(); };
            errors_ = 0;
            interval_ = poll_interval_;
            return;
        }
    } catch (dds::remote_config::network_exception & /** e */) {}
    handle_error();
}

void client_handler::tick() { rc_action_(); }

// NOLINTNEXTLINE(cppcoreguidelines-rvalue-reference-param-not-moved)
void client_handler::run(std::future<bool> &&exit_signal)
{
    std::chrono::time_point<std::chrono::steady_clock> before{0s};
    std::future_status fs = exit_signal.wait_for(0s);
    while (fs == std::future_status::timeout) {
        // If the thread is interrupted somehow, make sure to check that
        // the polling interval has actually elapsed.
        auto now = std::chrono::steady_clock::now();
        if ((now - before) >= interval_) {
            tick();
            before = now;
        }

        fs = exit_signal.wait_for(interval_);
    }
}
} // namespace dds::remote_config
