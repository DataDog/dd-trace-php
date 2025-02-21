// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_features_aggregator.hpp"
#include "../../exception.hpp"
#include <rapidjson/document.h>
#include <rapidjson/rapidjson.h>
#include <spdlog/spdlog.h>

namespace dds::remote_config {

namespace {
constexpr std::array<std::string_view, 4> expected_keys{
    "asm", "auto_user_instrum", "attack_mode", "api_security"};
} // namespace

void asm_features_aggregator::init(
    rapidjson::Document::AllocatorType *allocator)
{
    ruleset_ = rapidjson::Document(rapidjson::kObjectType, allocator);
}

void asm_features_aggregator::add(const config &config)
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
        auto doc_it = doc.FindMember(StringRef(key));
        if (doc_it != doc.MemberEnd()) {
            auto &value = doc_it->value;
            if (!value.IsObject()) {
                throw error_applying_config(
                    "Invalid type for " + std::string(key));
            }

            available_keys.emplace_back(key);
        }
    }

    // All keys should be correct so no need to check for their type again.
    for (const auto &key : available_keys) {
        // Make sure we override the value with the latest config.
        ruleset_.RemoveMember(StringRef(key));

        auto doc_it = doc.FindMember(StringRef(key));
        ruleset_.AddMember(
            StringRef(key), doc_it->value, ruleset_.GetAllocator());
    }
}

} // namespace dds::remote_config
