// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "asm_dd_aggregator.hpp"
#include "../../../exception.hpp"
#include "../../exception.hpp"
#include <rapidjson/document.h>

void dds::remote_config::asm_dd_aggregator::add(const config &config)
{
    rapidjson::Document doc(&ruleset_.GetAllocator());
    if (!json_helper::parse_json(config.read(), doc)) {
        throw error_applying_config("Invalid config contents");
    }

    if (!doc.IsObject()) {
        throw error_applying_config("Invalid type for config, expected object");
    }

    ruleset_ = std::move(doc);
}

void dds::remote_config::asm_dd_aggregator::remove(const config & /*config*/)
{
    if (fallback_rules_file_.empty()) {
        return;
    }

    auto ruleset = engine_ruleset::from_path(fallback_rules_file_);

    rapidjson::Document doc(&ruleset_.GetAllocator());
    doc.CopyFrom(ruleset.get_document(), doc.GetAllocator());

    ruleset_ = std::move(doc);
}
