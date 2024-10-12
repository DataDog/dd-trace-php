// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../config.hpp"
#include "../product.hpp"
#include <memory>
#include <string_view>
#include <unordered_set>
#include <vector>

namespace dds::remote_config {

class listener_base {
public:
    listener_base() = default;
    listener_base(const listener_base &) = default;
    listener_base(listener_base &&) = default;
    listener_base &operator=(const listener_base &) = default;
    listener_base &operator=(listener_base &&) = default;
    virtual ~listener_base() = default;

    virtual void on_update(const config &config) = 0;
    virtual void on_unapply(const config &config) = 0;
    [[nodiscard]] virtual std::unordered_set<product>
    get_supported_products() = 0;

    // Stateful listeners need to override these methods
    virtual void init() = 0;
    virtual void commit() = 0;
};

} // namespace dds::remote_config
