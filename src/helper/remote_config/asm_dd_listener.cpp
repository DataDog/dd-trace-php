// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_dd_listener.hpp"
#include "../json_helper.hpp"
#include "exception.hpp"
#include <rapidjson/document.h>

void dds::remote_config::asm_dd_listener::on_update(const config &config)
{
    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    rapidjson::Document serialized_doc;
    if (!json_helper::get_json_base64_encoded_content(
            config.contents, serialized_doc)) {
        throw error_applying_config("Invalid config contents");
    }

    engine_ruleset ruleset = dds::engine_ruleset(std::move(serialized_doc));
    engine_->update(ruleset, meta, metrics);
}

void dds::remote_config::asm_dd_listener::on_unapply(const config & /*config*/)
{
    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    engine_ruleset ruleset = engine_ruleset::from_path(fallback_rules_file_);
    engine_->update(ruleset, meta, metrics);
}
