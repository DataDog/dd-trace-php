// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../../../json_helper.hpp"
#include "config_aggregator.hpp"
#include <rapidjson/document.h>
#include <rapidjson/rapidjson.h>
#include <utility>

namespace dds::remote_config {

class asm_dd_aggregator : public config_aggregator_base {
public:
    static constexpr const char *ASM_DD_ADDED{"asm_dd_added"};
    static constexpr const char *ASM_DD_REMOVED{"asm_dd_removed"};

    asm_dd_aggregator() = default;
    asm_dd_aggregator(const asm_dd_aggregator &) = delete;
    asm_dd_aggregator(asm_dd_aggregator &&) = default;
    asm_dd_aggregator &operator=(const asm_dd_aggregator &) = delete;
    asm_dd_aggregator &operator=(asm_dd_aggregator &&) = default;
    ~asm_dd_aggregator() override = default;

    void init(rapidjson::Document::AllocatorType *allocator) override
    {
        change_set_ = rapidjson::Document(rapidjson::kObjectType, allocator);

        auto asm_dd_added_str = rapidjson::Value{ASM_DD_ADDED, *allocator};
        change_set_.AddMember(std::move(asm_dd_added_str),
            rapidjson::Value{rapidjson::kObjectType}, *allocator);

        auto asm_dd_removed_str = rapidjson::Value{ASM_DD_REMOVED, *allocator};
        change_set_.AddMember(std::move(asm_dd_removed_str),
            rapidjson::Value{rapidjson::kStringType}, *allocator);
    }

    void add(const config &config) override;
    void remove(const config &config) override;

    void aggregate(rapidjson::Document &doc) override
    {
        json_helper::merge_objects(doc, change_set_, doc.GetAllocator());
    }

protected:
    auto &allocator() { return change_set_.GetAllocator(); }
    rapidjson::Document change_set_;
};

} // namespace dds::remote_config
