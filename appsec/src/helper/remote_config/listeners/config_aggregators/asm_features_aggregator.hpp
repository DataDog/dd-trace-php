// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../../../json_helper.hpp"
#include "config_aggregator.hpp"
#include <rapidjson/document.h>

namespace dds::remote_config {

class asm_features_aggregator : public config_aggregator_base {
public:
    asm_features_aggregator() = default;
    asm_features_aggregator(const asm_features_aggregator &) = delete;
    asm_features_aggregator(asm_features_aggregator &&) = default;
    asm_features_aggregator &operator=(
        const asm_features_aggregator &) = delete;
    asm_features_aggregator &operator=(asm_features_aggregator &&) = default;
    ~asm_features_aggregator() override = default;

    void init(rapidjson::Document::AllocatorType *allocator) override;
    void add(const config &config) override;
    void remove(const config & /*config*/) override {}
    void aggregate(rapidjson::Document &doc) override
    {
        json_helper::merge_objects(doc, ruleset_, doc.GetAllocator());
    }

protected:
    rapidjson::Document ruleset_;
};

} // namespace dds::remote_config
