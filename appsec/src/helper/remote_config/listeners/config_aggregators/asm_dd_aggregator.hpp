// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../../../config.hpp"
#include "../../../json_helper.hpp"
#include "../../../parameter.hpp"
#include "config_aggregator.hpp"
#include <rapidjson/document.h>
#include <rapidjson/rapidjson.h>
#include <utility>

namespace dds::remote_config {

class asm_dd_aggregator : public config_aggregator_base {
public:
    asm_dd_aggregator() = default;
    explicit asm_dd_aggregator(std::string fallback_rules_file)
        : fallback_rules_file_(std::move(fallback_rules_file))
    {}
    asm_dd_aggregator(const asm_dd_aggregator &) = delete;
    asm_dd_aggregator(asm_dd_aggregator &&) = default;
    asm_dd_aggregator &operator=(const asm_dd_aggregator &) = delete;
    asm_dd_aggregator &operator=(asm_dd_aggregator &&) = default;
    ~asm_dd_aggregator() override = default;

    void init(rapidjson::Document::AllocatorType *allocator) override
    {
        ruleset_ = rapidjson::Document(rapidjson::kObjectType, allocator);
    }

    void add(const config &config) override;
    void remove(const config &config) override;

    void aggregate(rapidjson::Document &doc) override
    {
        json_helper::merge_objects(doc, ruleset_, doc.GetAllocator());
    }

protected:
    std::string fallback_rules_file_{};
    rapidjson::Document ruleset_;
};

} // namespace dds::remote_config
