// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "config_aggregator.hpp"
#include <rapidjson/document.h>

namespace dds::remote_config {

class asm_aggregator : public config_aggregator_base {
public:
    static constexpr char ASM_ADDED[]{"asm_added"};
    static constexpr char ASM_REMOVED[]{"asm_removed"};

    asm_aggregator() = default;
    asm_aggregator(const asm_aggregator &) = delete;
    asm_aggregator(asm_aggregator &&) = default;
    asm_aggregator &operator=(const asm_aggregator &) = delete;
    asm_aggregator &operator=(asm_aggregator &&) = default;
    ~asm_aggregator() override = default;

    void init(rapidjson::Document::AllocatorType *allocator) override;
    void add(const config &config) override;
    void remove(const config &config) override;
    void aggregate(rapidjson::Document &doc) override;

protected:
    auto &allocator() { return change_set_.GetAllocator(); }
    rapidjson::Document change_set_;
};

} // namespace dds::remote_config
