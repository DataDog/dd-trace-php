// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "config.hpp"
#include "remote_config_service.hpp"
#include <memory>

namespace dds::remote_config {

class product_listener_base {
public:
    explicit product_listener_base(
        std::shared_ptr<remote_config::remote_config_service>
            remote_config_service)
        : _remote_config_service(std::move(remote_config_service))
    {}
    product_listener_base(const product_listener_base &) = default;
    product_listener_base(product_listener_base &&) = default;
    product_listener_base &operator=(const product_listener_base &) = default;
    product_listener_base &operator=(product_listener_base &&) = default;
    virtual ~product_listener_base() = default;

    virtual void on_update(const config &config) = 0;
    virtual void on_unapply(const config &config) = 0;

protected:
    std::shared_ptr<remote_config::remote_config_service>
        _remote_config_service;
};

} // namespace dds::remote_config
