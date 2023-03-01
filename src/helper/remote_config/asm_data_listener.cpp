// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_data_listener.hpp"
#include "../json_helper.hpp"
#include "exception.hpp"
#include "spdlog/spdlog.h"
#include <optional>
#include <rapidjson/document.h>
#include <rapidjson/rapidjson.h>

namespace dds::remote_config {

using rule_data = asm_data_listener::rule_data;

namespace {
void extract_data(
    rapidjson::Value::ConstMemberIterator itr, rule_data &rule_data)
{
    for (const auto *data_entry_itr = itr->value.Begin();
         data_entry_itr != itr->value.End(); ++data_entry_itr) {
        if (!data_entry_itr->IsObject()) {
            throw dds::remote_config::error_applying_config(
                "Invalid config json contents: "
                "Entry on data not a valid object");
        }

        auto value_itr = dds::json_helper::get_field_of_type(
            data_entry_itr, "value", rapidjson::kStringType);
        if (!value_itr) {
            throw dds::remote_config::error_applying_config(
                "Invalid value of data entry");
        }

        std::optional<rapidjson::Value::ConstMemberIterator> expiration_itr =
            data_entry_itr->FindMember("expiration");
        if (expiration_itr == data_entry_itr->MemberEnd()) {
            expiration_itr = std::nullopt;
        } else if (expiration_itr.value()->value.GetType() !=
                   rapidjson::kNumberType) {
            throw dds::remote_config::error_applying_config(
                "Invalid type for expiration entry");
        }

        auto value = value_itr.value();

        auto previous = rule_data.data.find(value->value.GetString());
        if (previous != rule_data.data.end()) {
            if (!expiration_itr) {
                // This has no expiration so it last forever
                previous->second.expiration = std::nullopt;
            } else {
                auto expiration = expiration_itr.value()->value.GetUint64();
                if (previous->second.expiration &&
                    (expiration == 0 ||
                        previous->second.expiration < expiration)) {
                    previous->second.expiration =
                        expiration_itr.value()->value.GetUint64();
                }
            }
        } else {
            std::optional<uint64_t> expiration = std::nullopt;
            if (expiration_itr) {
                expiration = expiration_itr.value()->value.GetUint64();
            }
            rule_data.data.insert({value->value.GetString(),
                {value->value.GetString(), expiration}});
        }
    }
}

dds::engine_ruleset rules_to_engine_ruleset(
    const std::unordered_map<std::string, rule_data> &rules)
{
    rapidjson::Document document;
    rapidjson::Document::AllocatorType &alloc = document.GetAllocator();

    document.SetObject();

    rapidjson::Value rules_data(rapidjson::kArrayType);
    for (const auto &[key, rule] : rules) {
        rapidjson::Value parameter_rule(rapidjson::kObjectType);
        parameter_rule.AddMember("id", StringRef(rule.id), alloc);
        parameter_rule.AddMember("type", StringRef(rule.type), alloc);

        // Data
        rapidjson::Value data(rapidjson::kArrayType);
        for (const auto &[value, data_entry] : rule.data) {
            const auto &expiration = data_entry.expiration;
            rapidjson::Value data_parameter(rapidjson::kObjectType);
            if (expiration.has_value()) {
                data_parameter.AddMember(
                    "expiration", expiration.value(), alloc);
            }
            data_parameter.AddMember("value", StringRef(value), alloc);
            data.PushBack(data_parameter, alloc);
        }
        parameter_rule.AddMember("data", data, alloc);

        rules_data.PushBack(parameter_rule, alloc);
    }

    document.AddMember("rules_data", rules_data, alloc);

    return dds::engine_ruleset(std::move(document));
}

} // namespace

void asm_data_listener::on_update(const config &config)
{
    rapidjson::Document serialized_doc;
    if (!json_helper::get_json_base64_encoded_content(
            config.contents, serialized_doc)) {
        throw error_applying_config("Invalid config contents");
    }

    auto rules_data_itr = json_helper::get_field_of_type(
        serialized_doc, "rules_data", rapidjson::kArrayType);
    if (!rules_data_itr) {
        throw error_applying_config("Invalid config json contents: "
                                    "rules_data key missing or invalid");
    }

    auto rules_data_value = rules_data_itr.value();

    for (const auto *itr = rules_data_value->value.Begin();
         itr != rules_data_value->value.End(); ++itr) {
        if (!itr->IsObject()) {
            throw error_applying_config("Invalid config json contents: "
                                        "rules_data entry invalid");
        }

        auto id_itr =
            json_helper::get_field_of_type(itr, "id", rapidjson::kStringType);
        auto type_itr =
            json_helper::get_field_of_type(itr, "type", rapidjson::kStringType);
        auto data_itr =
            json_helper::get_field_of_type(itr, "data", rapidjson::kArrayType);
        if (!id_itr || !type_itr || !data_itr) {
            throw error_applying_config(
                "Invalid config json contents: "
                "rules_data missing a field or field is invalid");
        }

        auto id = id_itr.value();
        const std::string_view type = type_itr.value()->value.GetString();

        if (type != "data_with_expiration" && type != "ip_with_expiration") {
            SPDLOG_DEBUG("Unsupported rule data type {}", type);
            continue;
        }

        auto rule = rules_data_.find(id->value.GetString());
        if (rule == rules_data_.end()) { // New rule
            rule_data new_rule_data = {
                id->value.GetString(), std::string(type)};
            extract_data(data_itr.value(), new_rule_data);
            if (!new_rule_data.data.empty()) {
                rules_data_.emplace(id->value.GetString(), new_rule_data);
            }
        } else {
            extract_data(data_itr.value(), rule->second);
        }
    }
}

void asm_data_listener::commit()
{
    // TODO find a way to provide this information to the service
    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    engine_ruleset ruleset = rules_to_engine_ruleset(rules_data_);

    engine_->update(ruleset, meta, metrics);
}

} // namespace dds::remote_config
