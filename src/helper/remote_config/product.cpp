// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "product.hpp"
#include "exception.hpp"

void dds::remote_config::product::update_configs(
    std::unordered_map<std::string, dds::remote_config::config> &to_update)
{
    for (auto &[name, config] : to_update) {
        try {
            listener_->on_update(config);
            config.apply_state = dds::remote_config::protocol::config_state::
                applied_state::ACKNOWLEDGED;
            config.apply_error = "";
        } catch (dds::remote_config::error_applying_config &e) {
            config.apply_state = dds::remote_config::protocol::config_state::
                applied_state::ERROR;
            config.apply_error = e.what();
        }
    }
}

void dds::remote_config::product::unapply_configs(
    std::unordered_map<std::string, dds::remote_config::config> &to_unapply)
{
    for (auto &[path, conf] : to_unapply) {
        try {
            listener_->on_unapply(conf);
            conf.apply_state = dds::remote_config::protocol::config_state::
                applied_state::ACKNOWLEDGED;
            conf.apply_error = "";
        } catch (dds::remote_config::error_applying_config &e) {
            conf.apply_state = dds::remote_config::protocol::config_state::
                applied_state::ERROR;
            conf.apply_error = e.what();
        }
    }
}

void dds::remote_config::product::assign_configs(
    const std::unordered_map<std::string, config> &configs)
{
    std::unordered_map<std::string, config> to_update;
    // determine what each config given is
    for (const auto &[name, config] : configs) {
        auto previous_config = configs_.find(name);
        if (previous_config == configs_.end()) { // New config
            auto config_to_update = config;
            config_to_update.apply_state = dds::remote_config::protocol::
                config_state::applied_state::UNACKNOWLEDGED;
            to_update.emplace(name, config_to_update);
        } else { // Already existed
            if (config.hashes ==
                previous_config->second.hashes) { // No changes in config
                to_update.emplace(name, previous_config->second);
            } else { // Config updated
                auto config_to_update = config;
                config_to_update.apply_state = dds::remote_config::protocol::
                    config_state::applied_state::UNACKNOWLEDGED;
                to_update.emplace(name, config_to_update);
            }
            // configs_ at the end of this loop will contain only configs
            // which have to be unapply. This one has been classified as
            // something else and therefore, it has to be removed
            configs_.erase(previous_config);
        }
    }

    listener_->init();

    update_configs(to_update);
    unapply_configs(configs_);

    listener_->commit();

    // Save new state of configs
    configs_ = std::move(to_update);
};
