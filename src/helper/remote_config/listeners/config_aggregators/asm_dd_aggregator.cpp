// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_dd_aggregator.hpp"
#include "exception.hpp"
#include "remote_config/exception.hpp"
#include <rapidjson/document.h>

void dds::remote_config::asm_dd_aggregator::add(const config &config)
{
    if (!json_helper::get_json_base64_encoded_content(
            config.contents, ruleset_)) {
        throw error_applying_config("Invalid config contents");
    }
}

void dds::remote_config::asm_dd_aggregator::remove(const config & /*config*/)
{
    if (fallback_rules_file_.empty()) {
        return;
    }

    auto ruleset = read_file(fallback_rules_file_);
    rapidjson::ParseResult const result = ruleset_.Parse(ruleset.data());
    if ((result == nullptr) || !ruleset_.IsObject()) {
        throw parsing_error("invalid json rule");
    }
}
