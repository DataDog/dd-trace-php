// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "config.hpp"
#include <algorithm>
#include <iostream>
#include <map>
#include <string>
#include <vector>

namespace dds::remote_config {

class product_listener_base {
public:
    product_listener_base() = default;
    product_listener_base(const product_listener_base &) = default;
    product_listener_base(product_listener_base &&) = default;
    product_listener_base &operator=(const product_listener_base &) = default;
    product_listener_base &operator=(product_listener_base &&) = default;
    virtual ~product_listener_base() = default;

    virtual void on_update(const std::map<std::string, config> &configs) = 0;
    virtual void on_unapply(const std::map<std::string, config> &configs) = 0;
};

class product {
public:
    explicit product(
        std::string &&name, std::vector<product_listener_base *> &&listeners)
        : name_(std::move(name)), listeners_(std::move(listeners)){};
    void assign_configs(const std::map<std::string, config> &configs)
    {
        std::map<std::string, config> to_update;
        std::map<std::string, config> to_keep;

        for (const auto &config : configs) {
            auto previous_config = configs_.find(config.first);
            if (previous_config == configs_.end()) { // New config
                to_update.emplace(config.first, config.second);
            } else { // Already existed
                if (config.second.hashes ==
                    previous_config->second.hashes) { // No changes in config
                    to_keep.emplace(config.first, config.second);
                } else { // Config updated
                    to_update.emplace(config.first, config.second);
                }
                configs_.erase(previous_config);
            }
        }

        for (product_listener_base *listener : listeners_) {
            listener->on_update(to_update);
            listener->on_unapply(configs_);
        }
        to_keep.merge(to_update);

        configs_ = std::move(to_keep);
    };
    [[nodiscard]] std::map<std::string, config> get_configs() const
    {
        return configs_;
    };
    bool operator==(product const &b) const
    {
        return name_ == b.name_ && configs_ == b.configs_;
    }
    [[nodiscard]] std::string get_name() const { return name_; }

private:
    std::string name_;
    std::map<std::string, config> configs_;
    std::vector<product_listener_base *> listeners_;
};

} // namespace dds::remote_config
