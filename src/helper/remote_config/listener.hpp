// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "protocol/client.hpp"
#include <memory>
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

    virtual void on_update(const config &config) = 0;
    virtual void on_unapply(const config &config) = 0;
    [[nodiscard]] virtual const protocol::capabilities_e get_capabilities() = 0;
    [[nodiscard]] virtual const std::string_view get_name() = 0;

    // Stateful listeners need to override these methods
    virtual void init() = 0;
    virtual void commit() = 0;
};

} // namespace dds::remote_config
