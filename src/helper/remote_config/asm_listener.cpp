// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_listener.hpp"
#include "../json_helper.hpp"
#include "exception.hpp"
#include "spdlog/spdlog.h"
#include <optional>
#include <rapidjson/document.h>
#include <rapidjson/rapidjson.h>

namespace dds::remote_config {

namespace {

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
void merge_arrays(rapidjson::Value &destination, rapidjson::Value &source,
    rapidjson::Value::AllocatorType &allocator)
{
    for (auto *it = source.Begin(); it != source.End(); ++it) {
        destination.PushBack(*it, allocator);
    }
}

} // namespace

void asm_listener::on_update(const config &config)
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

            merge_arrays(destination_it->value, source_it->value,
                ruleset_.GetAllocator());
        }
    }
}

void asm_listener::init()
{
    ruleset_ = rapidjson::Document(rapidjson::kObjectType);
    static constexpr std::array<std::string_view, 4> expected_keys{
        "exclusions", "actions", "rules_override"};

    for (const auto &key : expected_keys) {
        rapidjson::Value empty_array(rapidjson::kArrayType);
        ruleset_.AddMember(
            StringRef(key), empty_array, ruleset_.GetAllocator());
    }
}

void asm_listener::commit()
{
    // TODO find a way to provide this information to the service
    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    engine_ruleset ruleset(std::move(ruleset_));

    engine_->update(ruleset, meta, metrics);
}

} // namespace dds::remote_config
