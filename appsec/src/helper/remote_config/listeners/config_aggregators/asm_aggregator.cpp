// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_aggregator.hpp"
#include "../../exception.hpp"
#include <optional>
#include <rapidjson/document.h>
#include <rapidjson/rapidjson.h>
#include <spdlog/spdlog.h>

namespace dds::remote_config {

namespace {
constexpr std::array<std::string_view, 4> expected_keys{
    "exclusions", "actions", "rules_override", "custom_rules"};
} // namespace

void asm_aggregator::init(rapidjson::Document::AllocatorType *allocator)
{
    ruleset_ = rapidjson::Document(rapidjson::kObjectType, allocator);
    for (const auto &key : expected_keys) {
        rapidjson::Value empty_array(rapidjson::kArrayType);
        ruleset_.AddMember(
            StringRef(key), empty_array, ruleset_.GetAllocator());
    }
}

void asm_aggregator::add(const config &config)
{
    rapidjson::Document doc(&ruleset_.GetAllocator());
    if (!json_helper::parse_json(config.read(), doc)) {
        throw error_applying_config("Invalid config contents");
    }

    if (!doc.IsObject()) {
        throw error_applying_config("Invalid type for config, expected object");
    }

    std::vector<std::string_view> available_keys;
    available_keys.reserve(doc.MemberCount());

    // Validate contents and extract available keys
    for (const auto &key : expected_keys) {
        auto it = doc.FindMember(StringRef(key));
        if (it != doc.MemberEnd()) {
            auto &value = it->value;
            if (!value.IsArray()) {
                throw error_applying_config(
                    "Invalid type for " + std::string(key));
            }
            available_keys.emplace_back(key);
        }
    }

    for (const auto &key : available_keys) {
        // All keys should be available so no need for extra checks
        auto dest = ruleset_.FindMember(StringRef(key));
        auto source = doc.FindMember(StringRef(key));
        json_helper::merge_arrays(
            dest->value, source->value, ruleset_.GetAllocator());
    }
}

} // namespace dds::remote_config
