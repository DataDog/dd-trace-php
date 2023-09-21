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
#include <rapidjson/document.h>

void dds::remote_config::asm_features_listener::on_update(const config &config)
{
    rapidjson::Document serialized_doc;
    if (!json_helper::get_json_base64_encoded_content(
            config.contents, serialized_doc)) {
        throw error_applying_config("Invalid config contents");
    }

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
            // This scenario should not happen since RC would remove the file
            // when appsec should not be enabled
            service_config_->disable_asm();
        }
    } else if (enabled_itr->value.GetType() == rapidjson::kTrueType) {
        service_config_->enable_asm();
    } else if (enabled_itr->value.GetType() == rapidjson::kFalseType) {
        // This scenario should not happen since RC would remove the file
        // when appsec should not be enabled
        service_config_->disable_asm();
    } else {
        throw error_applying_config(
            "Invalid config json encoded contents: enabled key invalid");
    }
}
