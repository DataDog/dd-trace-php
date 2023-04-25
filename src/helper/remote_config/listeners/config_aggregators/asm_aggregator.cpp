// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_aggregator.hpp"
#include "exception.hpp"
#include "remote_config/exception.hpp"
#include "spdlog/spdlog.h"
#include <optional>
#include <rapidjson/document.h>
#include <rapidjson/rapidjson.h>

namespace dds::remote_config {

void asm_aggregator::init(rapidjson::Document::AllocatorType *allocator)
{
    ruleset_ = rapidjson::Document(rapidjson::kObjectType, allocator);
    static constexpr std::array<std::string_view, 4> expected_keys{
        "exclusions", "actions", "rules_override", "custom_rules"};

    for (const auto &key : expected_keys) {
        rapidjson::Value empty_array(rapidjson::kArrayType);
        ruleset_.AddMember(
            StringRef(key), empty_array, ruleset_.GetAllocator());
    }
}

void asm_aggregator::add(const config &config)
{
    rapidjson::Document doc(rapidjson::kObjectType, &ruleset_.GetAllocator());
    if (!json_helper::get_json_base64_encoded_content(config.contents, doc)) {
        throw error_applying_config("Invalid config contents");
    }

    for (auto destination_it = ruleset_.MemberBegin();
         destination_it != ruleset_.MemberEnd(); ++destination_it) {
        auto source_it = doc.FindMember(destination_it->name);
        if (source_it != doc.MemberEnd()) {
            auto &source_value = source_it->value;
            if (!source_value.IsArray()) {
                const std::string &key = destination_it->name.GetString();
                throw dds::remote_config::error_applying_config(
                    "Invalid type for " + key);
            }

            json_helper::merge_arrays(destination_it->value, source_it->value,
                ruleset_.GetAllocator());
        }
    }
}

} // namespace dds::remote_config
