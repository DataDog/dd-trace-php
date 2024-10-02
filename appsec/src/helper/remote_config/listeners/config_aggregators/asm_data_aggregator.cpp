// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_data_aggregator.hpp"
#include "../../../json_helper.hpp"
#include "../../exception.hpp"
#include <optional>
#include <rapidjson/document.h>
#include <rapidjson/rapidjson.h>
#include <spdlog/spdlog.h>

namespace dds::remote_config {

using rule_data = asm_data_aggregator::rule_data;

void rule_data::emplace(rule_data::data_with_expiration &&data_point)
{
    auto it = data.find(data_point.value);
    if (it == data.end()) {
        data.emplace(data_point.value, std::move(data_point));
        return;
    }

    if (!data_point.expiration) {
        // This has no expiration so it last forever
        it->second.expiration = std::nullopt;
    } else {
        auto expiration = data_point.expiration.value();
        if (it->second.expiration &&
            (expiration == 0 || it->second.expiration < expiration)) {
            it->second.expiration = expiration;
        }
    }
}

void rule_data::merge(rule_data &other)
{
    for (auto &[key, value] : other.data) { emplace(std::move(value)); }
}

namespace {
void extract_data(
    rapidjson::Value::ConstMemberIterator itr, rule_data &rule_data)
{
    for (const auto *data_entry_it = itr->value.Begin();
         data_entry_it != itr->value.End(); ++data_entry_it) {
        if (!data_entry_it->IsObject()) {
            throw dds::remote_config::error_applying_config(
                "Invalid config json contents: "
                "Entry on data not a valid object");
        }

        rule_data::data_with_expiration data_point;

        auto value_it = dds::json_helper::get_field_of_type(
            data_entry_it, "value", rapidjson::kStringType);
        if (!value_it) {
            throw dds::remote_config::error_applying_config(
                "Invalid value of data entry");
        }
        data_point.value = value_it.value()->value.GetString();

        auto expiration_it = data_entry_it->FindMember("expiration");
        if (expiration_it != data_entry_it->MemberEnd()) {
            if (expiration_it->value.GetType() != rapidjson::kNumberType) {
                throw error_applying_config(
                    "Invalid type for expiration entry");
            }
            data_point.expiration = expiration_it->value.GetUint64();
        }

        rule_data.emplace(std::move(data_point));
    }
}

} // namespace

void asm_data_aggregator::add(const config &config)
{
    rapidjson::Document serialized_doc;
    if (!json_helper::parse_json(config.read(), serialized_doc)) {
        throw error_applying_config("Invalid config contents");
    }

    auto rules_data_itr = json_helper::get_field_of_type(
        serialized_doc, "rules_data", rapidjson::kArrayType);
    if (!rules_data_itr) {
        throw error_applying_config("Invalid config json contents: "
                                    "rules_data key missing or invalid");
    }

    auto rules_data_value = rules_data_itr.value();

    decltype(rules_data_) new_rules_data_;
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

        auto rule = new_rules_data_.find(id->value.GetString());
        if (rule == new_rules_data_.end()) { // New rule
            rule_data new_rule_data = {
                id->value.GetString(), std::string(type)};
            extract_data(data_itr.value(), new_rule_data);
            if (!new_rule_data.data.empty()) {
                new_rules_data_.emplace(id->value.GetString(), new_rule_data);
            }
        } else {
            extract_data(data_itr.value(), rule->second);
        }
    }

    // Once we reach this point, we can atomically update the aggregator
    for (auto &[key, value] : new_rules_data_) {
        auto it = rules_data_.find(key);
        if (it != rules_data_.end()) {
            it->second.merge(value);
        } else {
            rules_data_.emplace(key, std::move(value));
        }
    }
}

void asm_data_aggregator::aggregate(rapidjson::Document &doc)
{
    rapidjson::Document::AllocatorType &alloc = doc.GetAllocator();

    rapidjson::Value rules_data(rapidjson::kArrayType);
    for (const auto &[key, rule] : rules_data_) {
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

    doc.AddMember("rules_data", rules_data, alloc);
}

} // namespace dds::remote_config
