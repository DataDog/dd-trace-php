// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../../config.hpp"
#include <rapidjson/document.h>

namespace dds::remote_config {

class config_aggregator_base {
public:
    using unique_ptr = std::unique_ptr<config_aggregator_base>;

    config_aggregator_base() = default;
    config_aggregator_base(const config_aggregator_base &) = default;
    config_aggregator_base(config_aggregator_base &&) = default;
    config_aggregator_base &operator=(const config_aggregator_base &) = default;
    config_aggregator_base &operator=(config_aggregator_base &&) = default;
    virtual ~config_aggregator_base() = default;

    virtual void init(rapidjson::Document::AllocatorType *allocator) = 0;
    virtual void add(const config &config) = 0;
    virtual void remove(const config &config) = 0;
    virtual void aggregate(rapidjson::Document &doc) = 0;
};

} // namespace dds::remote_config
