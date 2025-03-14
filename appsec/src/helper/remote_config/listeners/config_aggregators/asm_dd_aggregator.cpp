// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_dd_aggregator.hpp"
#include "../../exception.hpp"
#include <rapidjson/document.h>

void dds::remote_config::asm_dd_aggregator::add(const config &config)
{
    rapidjson::Document new_ruleset(&allocator());
    if (!json_helper::parse_json(config.read(), new_ruleset)) {
        throw error_applying_config("Invalid config contents");
    }

    if (!new_ruleset.IsObject()) {
        throw error_applying_config("Invalid type for config, expected object");
    }

    rapidjson::Value added_value{rapidjson::kObjectType};
    auto key_json = rapidjson::Value{config.rc_path, allocator()};
    added_value.AddMember(
        std::move(key_json), std::move(new_ruleset), allocator());

    // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-array-to-pointer-decay)
    change_set_[ASM_DD_ADDED] = std::move(added_value);
}

void dds::remote_config::asm_dd_aggregator::remove(const config &config)
{
    // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-array-to-pointer-decay)
    change_set_[ASM_DD_REMOVED].SetString(config.rc_path, allocator());
}
