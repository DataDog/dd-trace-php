// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "config.hpp"
#include "listener.hpp"
#include <algorithm>
#include <iostream>
#include <memory>
#include <string>
#include <unordered_map>
#include <vector>

namespace dds::remote_config {

class product {
public:
    product(std::shared_ptr<dds::remote_config::product_listener_base> listener)
        : listener_(std::move(listener))
    {
        if (listener_ == nullptr) {
            throw std::runtime_error("invalid listener");
        }
        name_ = listener_->get_name();
    }

    void assign_configs(const std::unordered_map<std::string, config> &configs);
    [[nodiscard]] const std::unordered_map<std::string, config> &
    get_configs() const
    {
        return configs_;
    };
    bool operator==(product const &b) const
    {
        return name_ == b.name_ && configs_ == b.configs_;
    }
    [[nodiscard]] const std::string &get_name() const { return name_; }
    [[nodiscard]] const protocol::capabilities_e get_capabilities() const
    {
        return listener_->get_capabilities();
    }

protected:
    void update_configs(
        std::unordered_map<std::string, dds::remote_config::config> &to_update);
    void unapply_configs(
        std::unordered_map<std::string, dds::remote_config::config>
            &to_unapply);

    std::string name_;
    std::unordered_map<std::string, config> configs_;
    std::shared_ptr<product_listener_base> listener_;
};

} // namespace dds::remote_config
