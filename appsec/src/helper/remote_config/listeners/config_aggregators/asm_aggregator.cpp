// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_aggregator.hpp"
#include "../../../json_helper.hpp"
#include "../../exception.hpp"
#include <rapidjson/document.h>
#include <rapidjson/rapidjson.h>
#include <spdlog/spdlog.h>

namespace dds::remote_config {

void asm_aggregator::init(rapidjson::Document::AllocatorType *allocator)
{
    change_set_ = rapidjson::Document(rapidjson::kObjectType, allocator);

    // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-array-to-pointer-decay)
    change_set_.AddMember(rapidjson::Value{ASM_ADDED, *allocator},
        rapidjson::Value{rapidjson::kObjectType}, *allocator);

    // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-array-to-pointer-decay)
    change_set_.AddMember(rapidjson::Value{ASM_REMOVED, *allocator},
        rapidjson::Value{rapidjson::kArrayType}, *allocator);
}

void asm_aggregator::add(const config &config)
{
    rapidjson::Document doc(&allocator());
    if (!json_helper::parse_json(config.read(), doc)) {
        throw error_applying_config("Invalid config contents");
    }

    if (!doc.IsObject()) {
        throw error_applying_config("Invalid type for config, expected object");
    }

    // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-array-to-pointer-decay)
    change_set_[ASM_ADDED].AddMember(
        rapidjson::Value{config.rc_path, allocator()}, doc, allocator());
}

void asm_aggregator::remove(const config &config)
{
    rapidjson::Document doc(&allocator());
    if (!json_helper::parse_json(config.read(), doc)) {
        throw error_applying_config("Invalid config contents");
    }

    if (!doc.IsObject()) {
        throw error_applying_config("Invalid type for config, expected object");
    }

    // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-array-to-pointer-decay)
    change_set_[ASM_REMOVED].PushBack(
        rapidjson::Value{config.rc_path, allocator()}, allocator());
}

void asm_aggregator::aggregate(rapidjson::Document &doc)
{
    json_helper::merge_objects(doc, change_set_, allocator());
}
} // namespace dds::remote_config
