// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../../../common.hpp"
#include "../../mocks.hpp"
#include "json_helper.hpp"
#include "remote_config/exception.hpp"
#include "remote_config/listeners/config_aggregators/asm_dd_aggregator.hpp"
#include <rapidjson/document.h>

const std::string waf_rule =
    R"({"rules": [{"id": "someId", "name": "Test", "tags": {"type": "security_scanner", "category": "attack_attempt"}, "conditions": [{"parameters": {"inputs": [{"address": "http.url"} ], "regex": "(?i)\\evil\\b"}, "operator": "match_regex"} ], "transformers": [], "on_match": ["block"] } ] })";

namespace dds::remote_config {

using mock::generate_config;

TEST(RemoteConfigAsmDdAggregator, AddConfig)
{
    remote_config::asm_dd_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(generate_config("ASM_DD", waf_rule));
    aggregator.aggregate(doc);

    const auto &rules = doc["rules"];
    const auto &first = rules[0];
    EXPECT_STREQ("someId", first.FindMember("id")->value.GetString());
}

TEST(RemoteConfigAsmDdAggregator, RemoveConfig)
{
    remote_config::asm_dd_aggregator aggregator(create_sample_rules_ok());

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.remove(generate_config("ASM_DD", waf_rule));
    aggregator.aggregate(doc);

    const auto &rules = doc["rules"];
    const auto &first = rules[0];
    EXPECT_STREQ("blk-001-001", first.FindMember("id")->value.GetString());
}

TEST(RemoteConfigAsmDdAggregator, AddConfigInvalidBase64Content)
{
    std::string invalid_content = "&&&";
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    remote_config::config config =
        generate_config("ASM_DD", invalid_content, false);

    remote_config::asm_dd_aggregator aggregator;
    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDdAggregator, AddConfigInvalidJsonContent)
{
    std::string invalid_content = "InvalidJsonContent";
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    remote_config::config config =
        generate_config("ASM_DD", invalid_content, true);

    remote_config::asm_dd_aggregator aggregator;
    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}
} // namespace dds::remote_config
