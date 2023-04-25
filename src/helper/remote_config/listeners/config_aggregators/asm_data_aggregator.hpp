// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "config.hpp"
#include "config_aggregator.hpp"
#include "engine.hpp"
#include "parameter.hpp"
#include <optional>
#include <rapidjson/document.h>
#include <utility>

namespace dds::remote_config {

class asm_data_aggregator : public config_aggregator_base {
public:
    struct rule_data {
        struct data_with_expiration {
            std::string value;
            std::optional<uint64_t> expiration;
        };

        std::string id;
        std::string type;
        std::map<std::string, data_with_expiration> data;
    };

    asm_data_aggregator() = default;
    asm_data_aggregator(const asm_data_aggregator &) = delete;
    asm_data_aggregator(asm_data_aggregator &&) = default;
    asm_data_aggregator &operator=(const asm_data_aggregator &) = delete;
    asm_data_aggregator &operator=(asm_data_aggregator &&) = default;
    ~asm_data_aggregator() override = default;

    void init(rapidjson::Document::AllocatorType * /*allocator*/) override
    {
        rules_data_.clear();
    }

    void add(const config &config) override;
    void remove(const config &config) override {}
    void aggregate(rapidjson::Document &doc) override;

protected:
    std::unordered_map<std::string, rule_data> rules_data_;
};

} // namespace dds::remote_config
