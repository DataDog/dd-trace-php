// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_features_listener.hpp"
#include "../../json_helper.hpp"
#include "../../utils.hpp"
#include "../exception.hpp"
#include "config_aggregators/asm_features_aggregator.hpp"
#include <rapidjson/document.h>

namespace dds::remote_config {

void asm_features_listener::init()
{
    ruleset_ = rapidjson::Document(rapidjson::kObjectType);
    aggregator_ = std::make_unique<asm_features_aggregator>();
    aggregator_->init(&ruleset_.GetAllocator());
}

void asm_features_listener::on_update(const config &config)
{
    aggregator_->add(config);
}

void asm_features_listener::parse_asm_activation_config()
{
    auto asm_itr =
        json_helper::get_field_of_type(ruleset_, "asm", rapidjson::kObjectType);
    if (!asm_itr) {
        service_config_->unset_asm();
        return;
    }

    auto enabled_itr = asm_itr.value()->value.FindMember("enabled");
    if (enabled_itr == asm_itr.value()->value.MemberEnd()) {
        service_config_->unset_asm();
        return;
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
        service_config_->unset_asm();
    }
}

void asm_features_listener::parse_auto_user_instrum_config()
{
    if (!json_helper::field_exists(ruleset_, "auto_user_instrum")) {
        service_config_->unset_auto_user_instrum();
        return;
    }

    auto auto_user_instrum_itr = json_helper::get_field_of_type(
        ruleset_, "auto_user_instrum", rapidjson::kObjectType);
    if (!auto_user_instrum_itr) {
        service_config_->set_auto_user_instrum(auto_user_instrum_mode::UNKNOWN);
        return;
    }

    auto mode_itr = auto_user_instrum_itr.value()->value.FindMember("mode");
    if (mode_itr == auto_user_instrum_itr.value()->value.MemberEnd()) {
        service_config_->set_auto_user_instrum(auto_user_instrum_mode::UNKNOWN);
        return;
    }

    auto mode = auto_user_instrum_mode::UNKNOWN;

    if (mode_itr->value.GetType() == rapidjson::kStringType) {
        if (dd_tolower(mode_itr->value.GetString()) ==
            std::string("identification")) {
            mode = auto_user_instrum_mode::IDENTIFICATION;
        } else if (dd_tolower(mode_itr->value.GetString()) ==
                   std::string("anonymization")) {
            mode = auto_user_instrum_mode::ANONYMIZATION;
        } else if (dd_tolower(mode_itr->value.GetString()) ==
                   std::string("disabled")) {
            mode = auto_user_instrum_mode::DISABLED;
        }
    }

    service_config_->set_auto_user_instrum(mode);
}

void asm_features_listener::commit()
{
    aggregator_->aggregate(ruleset_);
    parse_asm_activation_config();
    parse_auto_user_instrum_config();
}

} // namespace dds::remote_config
