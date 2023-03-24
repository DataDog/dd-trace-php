// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "service.hpp"

namespace dds {

// This will limit the max increase to 4.266666667 minutes
static constexpr std::uint16_t max_increment = 8;

service::service(service_identifier id, std::shared_ptr<engine> engine,
    remote_config::client::ptr &&rc_client,
    std::shared_ptr<service_config> service_config,
    const std::chrono::milliseconds &poll_interval)
    : id_(std::move(id)), engine_(std::move(engine)),
      service_config_(std::move(service_config)),
      rc_client_(std::move(rc_client)), poll_interval_(poll_interval),
      interval_(poll_interval)
{
    // It starts checking if rc is available
    rc_action_ = [this] { discover(); };
    // The engine should always be valid
    if (!engine_) {
        throw std::runtime_error("invalid engine");
    }

    if (rc_client_) {
        handler_ = std::thread(&service::run, this, exit_.get_future());
    }
}

service::~service()
{
    if (handler_.joinable()) {
        exit_.set_value(true);
        handler_.join();
    }
}

service::ptr service::from_settings(const service_identifier &id,
    const dds::engine_settings &eng_settings,
    const remote_config::settings &rc_settings,
    std::map<std::string_view, std::string> &meta,
    std::map<std::string_view, double> &metrics, bool dynamic_enablement)
{
    auto engine_ptr = engine::from_settings(eng_settings, meta, metrics);

    std::chrono::milliseconds const poll_interval{rc_settings.poll_interval};
    auto service_config = std::make_shared<dds::service_config>();
    service_config->dynamic_enablement = dynamic_enablement;
    service_config->dynamic_engine = eng_settings.rules_file.empty();

    auto rc_client = remote_config::client::from_settings(
        id, rc_settings, service_config, engine_ptr);

    return std::make_shared<service>(id, engine_ptr, std::move(rc_client),
        std::move(service_config),
        std::chrono::milliseconds{rc_settings.poll_interval});
}

void service::handle_error()
{
    rc_action_ = [this] { discover(); };
    interval_ = std::chrono::duration_cast<std::chrono::milliseconds>(
        poll_interval_ * pow(2, std::min(errors_, max_increment)));
    if (errors_ < std::numeric_limits<std::uint16_t>::max() - 1) {
        errors_++;
    }
}

void service::poll()
{
    try {
        rc_client_->poll();
    } catch (dds::remote_config::network_exception & /** e */) {
        handle_error();
    }
}
void service::discover()
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

void service::run(std::future<bool> &&exit_signal)
{
    std::chrono::time_point<std::chrono::steady_clock> before{0s};
    std::future_status fs = exit_signal.wait_for(0s);
    while (fs == std::future_status::timeout) {
        // If the thread is interrupted somehow, make sure to check that
        // the polling interval has actually elapsed.
        auto now = std::chrono::steady_clock::now();
        if ((now - before) >= interval_) {
            rc_action_();
            before = now;
        }

        fs = exit_signal.wait_for(interval_);
    }
}
} // namespace dds
