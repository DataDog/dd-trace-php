// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_features_listener.hpp"
#include "exception.hpp"
#include "json_helper.hpp"
#include "remote_config/exception.hpp"
#include "utils.hpp"
#include <algorithm>

void dds::remote_config::asm_features_listener::parse_api_security(
    const rapidjson::Document &serialized_doc)
{
    auto api_security_itr = json_helper::get_field_of_type(
        serialized_doc, "api_security", rapidjson::kObjectType);

    if (!api_security_itr) {
        throw error_applying_config("Invalid config json encoded contents: "
                                    "api_security key missing or invalid");
    }

    auto request_sample_rate_itr =
        api_security_itr.value()->value.FindMember("request_sample_rate");
    if (request_sample_rate_itr ==
        api_security_itr.value()->value.MemberEnd()) {
        throw error_applying_config("Invalid config json encoded contents: "
                                    "request_sample_rate key missing");
    }

    if (request_sample_rate_itr->value.GetType() != rapidjson::kNumberType ||
        !request_sample_rate_itr->value.IsDouble()) {
        throw error_applying_config("Invalid config json encoded contents: "
                                    "request_sample_rate is not double");
    }

    service_config_->set_request_sample_rate(
        request_sample_rate_itr->value.GetDouble());
}

void dds::remote_config::asm_features_listener::parse_asm(
    const rapidjson::Document &serialized_doc)
{
    auto asm_itr = json_helper::get_field_of_type(
        serialized_doc, "asm", rapidjson::kObjectType);

    if (!asm_itr) {
        throw error_applying_config("Invalid config json encoded contents: "
                                    "asm key missing or invalid");
    }

    auto enabled_itr = asm_itr.value()->value.FindMember("enabled");
    if (enabled_itr == asm_itr.value()->value.MemberEnd()) {
        throw error_applying_config(
            "Invalid config json encoded contents: enabled key missing");
    }

    if (enabled_itr->value.GetType() == rapidjson::kStringType) {
        if (dd_tolower(enabled_itr->value.GetString()) == std::string("true")) {
            service_config_->enable_asm();
        } else {
            service_config_->disable_asm();
        }
    } else if (enabled_itr->value.GetType() == rapidjson::kTrueType) {
        service_config_->enable_asm();
    } else if (enabled_itr->value.GetType() == rapidjson::kFalseType) {
        service_config_->disable_asm();
    } else {
        throw error_applying_config(
            "Invalid config json encoded contents: enabled key invalid");
    }
}

void dds::remote_config::asm_features_listener::on_update(const config &config)
{
    rapidjson::Document serialized_doc;
    if (!json_helper::get_json_base64_encoded_content(
            config.contents, serialized_doc)) {
        throw error_applying_config("Invalid config contents");
    }

    if (dynamic_enablement_) {
        parse_asm(serialized_doc);
    }
    if (api_security_enabled_) {
        parse_api_security(serialized_doc);
    }
}
