// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "service.hpp"
#include "remote_config/asm_features_listener.hpp"

namespace dds {

service::service(service_identifier id, std::shared_ptr<engine> engine,
    remote_config::client::ptr &&rc_client,
    std::shared_ptr<service_config> service_config,
    const std::chrono::milliseconds &poll_interval)
    : id_(std::move(id)), engine_(std::move(engine)),
      service_config_(std::move(service_config)),
      rc_client_(std::move(rc_client)), poll_interval_(poll_interval)
{
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
    std::map<std::string_view, double> &metrics)
{
    // no cache hit
    auto &&rules_path = eng_settings.rules_file_or_default();
    std::shared_ptr engine_ptr{engine::create(eng_settings.trace_rate_limit)};

    try {
        SPDLOG_DEBUG("Will load WAF rules from {}", rules_path);
        // may throw std::exception
        const subscriber::ptr waf =
            waf::instance::from_settings(eng_settings, meta, metrics);
        engine_ptr->subscribe(waf);
    } catch (...) {
        DD_STDLOG(DD_STDLOG_WAF_INIT_FAILED, rules_path);
        throw;
    }

    std::chrono::milliseconds poll_interval{rc_settings.poll_interval};

    // Create remote configs stuff
    auto service_config = std::make_shared<dds::service_config>();
    auto listener =
        std::make_shared<remote_config::asm_features_listener>(service_config);
    std::vector<remote_config::product> products = {{"ASM_FEATURES", listener}};
    std::vector<remote_config::protocol::capabilities_e> capabilities = {
        remote_config::protocol::capabilities_e::ASM_ACTIVATION};

    auto rc_client = remote_config::client::from_settings(
        id, rc_settings, std::move(products), std::move(capabilities));

    return std::make_shared<service>(id, engine_ptr, std::move(rc_client),
        std::move(service_config),
        std::chrono::milliseconds{rc_settings.poll_interval});
}

void service::run(std::future<bool> &&exit_signal)
{
    std::chrono::time_point<std::chrono::steady_clock> before{0s};
    std::future_status fs = exit_signal.wait_for(0s);
    while (fs == std::future_status::timeout) {
        // If the thread is interrupted somehow, make sure to check that
        // the polling interval has actually elapsed.
        auto now = std::chrono::steady_clock::now();
        if ((now - before) >= poll_interval_) {
            rc_client_->poll();
            before = now;
        }

        fs = exit_signal.wait_for(poll_interval_);
    }
}
} // namespace dds
