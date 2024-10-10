// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../../../common.hpp"
#include "../../mocks.hpp"
#include "json_helper.hpp"
#include "remote_config/exception.hpp"
#include "remote_config/listeners/config_aggregators/asm_aggregator.hpp"
#include "remote_config/product.hpp"
#include <rapidjson/document.h>

namespace dds::remote_config {
namespace {

using mock::get_config;

const std::string waf_rule =
    R"({"version":"2.1","rules":[{"id":"1","name":"rule1","tags":{"type":"flow1","category":"category1"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg1","key_path":[]}],"regex":".*"}}]}]})";

ACTION_P(SaveDocument, param)
{
    rapidjson::Document &document =
        *reinterpret_cast<rapidjson::Document *>(param);

    arg0.copy(document);
}

TEST(RemoteConfigAsmAggregator, EmptyCommit)
{
    remote_config::asm_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);
}

TEST(RemoteConfigAsmAggregator, EmptyConfigThrows)
{
    remote_config::asm_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);

    aggregator.init(&doc.GetAllocator());
    EXPECT_THROW(aggregator.add(get_config("ASM", {})),
        std::runtime_error); // mmap failure

    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);
}

TEST(RemoteConfigAsmAggregator, IncorrectTypeThrows)
{
    const std::string rule_override =
        R"({"rules_override": {"rules_target": [{"tags": {"confidence": "1"}}], "on_match": ["block"]}})";

    remote_config::asm_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(aggregator.add(get_config("ASM", rule_override)),
        remote_config::error_applying_config);

    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);
}

TEST(RemoteConfigAsmAggregator, RulesOverrideEmpty)
{
    const std::string rule_override = R"({"rules_override": []})";

    remote_config::asm_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", rule_override));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);
}

TEST(RemoteConfigAsmAggregator, RulesOverrideSingleConfig)
{
    const std::string rule_override =
        R"({"rules_override": [{"rules_target": [{"tags": {"confidence": "1"}}], "on_match": ["block"]}]})";

    remote_config::asm_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", rule_override));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 1);

    const auto &ovrd = overrides[0];
    EXPECT_TRUE(ovrd.IsObject());

    {
        auto it = ovrd.FindMember("rules_target");
        EXPECT_NE(it, ovrd.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
    }

    {
        auto it = ovrd.FindMember("on_match");
        EXPECT_NE(it, ovrd.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
    }

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);
}

TEST(RemoteConfigAsmAggregator, RulesOverrideMultipleConfigs)
{
    const std::string rule_override =
        R"({"rules_override": [{"rules_target": [{"tags": {"confidence": "1"}}], "on_match": ["block"]}]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", rule_override));
    aggregator.add(get_config("ASM", rule_override));
    aggregator.add(get_config("ASM", rule_override));
    aggregator.add(get_config("ASM", rule_override));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 4);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);

    for (auto *it = overrides.Begin(); it != overrides.End(); ++it) {
        const auto &ovrd = *it;
        EXPECT_TRUE(ovrd.IsObject());

        {
            auto it = ovrd.FindMember("rules_target");
            EXPECT_NE(it, ovrd.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
        }

        {
            auto it = ovrd.FindMember("on_match");
            EXPECT_NE(it, ovrd.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
        }
    }
}

TEST(RemoteConfigAsmAggregator, RulesOverrideIgnoreInvalidConfigs)
{
    const std::string rule_override =
        R"({"rules_override": [{"rules_target": [{"tags": {"confidence": "1"}}], "on_match": ["block"]}]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", rule_override));
    aggregator.add(get_config("ASM", rule_override));
    {
        const std::string invalid =
            R"({"rules_override": {"rules_target": [{"tags": {"confidence": "1"}}], "on_match": ["block"]}})";
        EXPECT_THROW(aggregator.add(get_config("ASM", invalid)),
            remote_config::error_applying_config);
    }
    aggregator.add(get_config("ASM", rule_override));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 3);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);

    for (auto *it = overrides.Begin(); it != overrides.End(); ++it) {
        const auto &ovrd = *it;
        EXPECT_TRUE(ovrd.IsObject());

        {
            auto it = ovrd.FindMember("rules_target");
            EXPECT_NE(it, ovrd.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
        }

        {
            auto it = ovrd.FindMember("on_match");
            EXPECT_NE(it, ovrd.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
        }
    }
}

TEST(RemoteConfigAsmAggregator, RulesOverridesConfigCycling)
{
    remote_config::asm_aggregator aggregator;

    const std::string rule_override =
        R"({"rules_override": [{"rules_target": [{"tags": {"confidence": "1"}}], "on_match": ["block"]}]})";
    {
        rapidjson::Document doc(rapidjson::kObjectType);
        aggregator.init(&doc.GetAllocator());
        aggregator.add(get_config("ASM", rule_override));
        aggregator.aggregate(doc);

        const auto &overrides = doc["rules_override"];
        EXPECT_TRUE(overrides.IsArray());
        EXPECT_EQ(overrides.Size(), 1);

        const auto &exclusions = doc["exclusions"];
        EXPECT_TRUE(exclusions.IsArray());
        EXPECT_EQ(exclusions.Size(), 0);

        const auto &actions = doc["actions"];
        EXPECT_TRUE(actions.IsArray());
        EXPECT_EQ(actions.Size(), 0);

        const auto &custom_rules = doc["custom_rules"];
        EXPECT_TRUE(custom_rules.IsArray());
        EXPECT_EQ(custom_rules.Size(), 0);

        for (auto *it = overrides.Begin(); it != overrides.End(); ++it) {
            const auto &ovrd = *it;
            EXPECT_TRUE(ovrd.IsObject());

            {
                auto it = ovrd.FindMember("rules_target");
                EXPECT_NE(it, ovrd.MemberEnd());
                EXPECT_TRUE(it->value.IsArray());
            }

            {
                auto it = ovrd.FindMember("on_match");
                EXPECT_NE(it, ovrd.MemberEnd());
                EXPECT_TRUE(it->value.IsArray());
            }
        }
    }

    {
        rapidjson::Document doc(rapidjson::kObjectType);
        aggregator.init(&doc.GetAllocator());
        aggregator.add(get_config("ASM", rule_override));
        aggregator.add(get_config("ASM", rule_override));
        aggregator.add(get_config("ASM", rule_override));
        aggregator.aggregate(doc);

        const auto &overrides = doc["rules_override"];
        EXPECT_TRUE(overrides.IsArray());
        EXPECT_EQ(overrides.Size(), 3);

        const auto &exclusions = doc["exclusions"];
        EXPECT_TRUE(exclusions.IsArray());
        EXPECT_EQ(exclusions.Size(), 0);

        const auto &actions = doc["actions"];
        EXPECT_TRUE(actions.IsArray());
        EXPECT_EQ(actions.Size(), 0);

        const auto &custom_rules = doc["custom_rules"];
        EXPECT_TRUE(custom_rules.IsArray());
        EXPECT_EQ(custom_rules.Size(), 0);

        for (auto *it = overrides.Begin(); it != overrides.End(); ++it) {
            const auto &ovrd = *it;
            EXPECT_TRUE(ovrd.IsObject());

            {
                auto it = ovrd.FindMember("rules_target");
                EXPECT_NE(it, ovrd.MemberEnd());
                EXPECT_TRUE(it->value.IsArray());
            }

            {
                auto it = ovrd.FindMember("on_match");
                EXPECT_NE(it, ovrd.MemberEnd());
                EXPECT_TRUE(it->value.IsArray());
            }
        }
    }
}

TEST(RemoteConfigAsmAggregator, ActionsSingleConfig)
{
    const std::string action_definitions =
        R"({"actions": [{"id": "redirect", "type": "redirect_request", "parameters": {"status_code": "303", "location": "localhost"}}]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", action_definitions));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 1);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);
}

TEST(RemoteConfigAsmAggregator, ActionsMultipleConfigs)
{
    const std::string action_definitions =
        R"({"actions": [{"id": "redirect", "type": "redirect_request", "parameters": {"status_code": "303", "location": "localhost"}}]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", action_definitions));
    aggregator.add(get_config("ASM", action_definitions));
    aggregator.add(get_config("ASM", action_definitions));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 3);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);
}

TEST(RemoteConfigAsmAggregator, ActionsIgnoreInvalidConfigs)
{
    const std::string action_definitions =
        R"({"actions": [{"id": "redirect", "type": "redirect_request", "parameters": {"status_code": "303", "location": "localhost"}}]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", action_definitions));
    {
        const std::string invalid =
            R"({"actions": {"id": "redirect", "type": "redirect_request", "parameters": {"status_code": "303", "location": "localhost"}}})";
        EXPECT_THROW(aggregator.add(get_config("ASM", invalid)),
            remote_config::error_applying_config);
    }
    aggregator.add(get_config("ASM", action_definitions));
    aggregator.add(get_config("ASM", action_definitions));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 3);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);
}

TEST(RemoteConfigAsmAggregator, ActionsConfigCycling)
{
    const std::string action_definitions =
        R"({"actions": [{"id": "redirect", "type": "redirect_request", "parameters": {"status_code": "303", "location": "localhost"}}]})";

    remote_config::asm_aggregator aggregator;

    {
        rapidjson::Document doc(rapidjson::kObjectType);
        aggregator.init(&doc.GetAllocator());
        aggregator.add(get_config("ASM", action_definitions));
        aggregator.add(get_config("ASM", action_definitions));
        aggregator.add(get_config("ASM", action_definitions));
        aggregator.aggregate(doc);

        const auto &overrides = doc["rules_override"];
        EXPECT_TRUE(overrides.IsArray());
        EXPECT_EQ(overrides.Size(), 0);

        const auto &exclusions = doc["exclusions"];
        EXPECT_TRUE(exclusions.IsArray());
        EXPECT_EQ(exclusions.Size(), 0);

        const auto &actions = doc["actions"];
        EXPECT_TRUE(actions.IsArray());
        EXPECT_EQ(actions.Size(), 3);

        const auto &custom_rules = doc["custom_rules"];
        EXPECT_TRUE(custom_rules.IsArray());
        EXPECT_EQ(custom_rules.Size(), 0);
    }

    {
        rapidjson::Document doc(rapidjson::kObjectType);
        aggregator.init(&doc.GetAllocator());
        aggregator.add(get_config("ASM", action_definitions));
        aggregator.aggregate(doc);

        const auto &overrides = doc["rules_override"];
        EXPECT_TRUE(overrides.IsArray());
        EXPECT_EQ(overrides.Size(), 0);

        const auto &exclusions = doc["exclusions"];
        EXPECT_TRUE(exclusions.IsArray());
        EXPECT_EQ(exclusions.Size(), 0);

        const auto &actions = doc["actions"];
        EXPECT_TRUE(actions.IsArray());
        EXPECT_EQ(actions.Size(), 1);

        const auto &custom_rules = doc["custom_rules"];
        EXPECT_TRUE(custom_rules.IsArray());
        EXPECT_EQ(custom_rules.Size(), 0);
    }
}

TEST(RemoteConfigAsmAggregator, ExclusionsSingleConfig)
{
    const std::string update =
        R"({"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", update));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 1);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);
}

TEST(RemoteConfigAsmAggregator, ExclusionsMultipleConfigs)
{
    const std::string update =
        R"({"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", update));
    aggregator.add(get_config("ASM", update));
    aggregator.add(get_config("ASM", update));
    aggregator.add(get_config("ASM", update));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 4);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);
}

TEST(RemoteConfigAsmAggregator, ExclusionsIgnoreInvalidConfigs)
{
    const std::string update =
        R"({"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", update));
    {
        const std::string invalid =
            R"({"exclusions": {"id":1,"rules_target":[{"rule_id":1}]}})";
        EXPECT_THROW(aggregator.add(get_config("ASM", invalid)),
            remote_config::error_applying_config);
    }
    aggregator.add(get_config("ASM", update));
    aggregator.add(get_config("ASM", update));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 3);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 0);
}

TEST(RemoteConfigAsmAggregator, ExclusionsConfigCycling)
{
    const std::string update =
        R"({"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}]})";

    remote_config::asm_aggregator aggregator;

    {
        rapidjson::Document doc(rapidjson::kObjectType);
        aggregator.init(&doc.GetAllocator());
        aggregator.add(get_config("ASM", update));
        aggregator.add(get_config("ASM", update));
        aggregator.add(get_config("ASM", update));
        aggregator.add(get_config("ASM", update));
        aggregator.aggregate(doc);

        const auto &overrides = doc["rules_override"];
        EXPECT_TRUE(overrides.IsArray());
        EXPECT_EQ(overrides.Size(), 0);

        const auto &exclusions = doc["exclusions"];
        EXPECT_TRUE(exclusions.IsArray());
        EXPECT_EQ(exclusions.Size(), 4);

        const auto &actions = doc["actions"];
        EXPECT_TRUE(actions.IsArray());
        EXPECT_EQ(actions.Size(), 0);

        const auto &custom_rules = doc["custom_rules"];
        EXPECT_TRUE(custom_rules.IsArray());
        EXPECT_EQ(custom_rules.Size(), 0);
    }

    {
        rapidjson::Document doc(rapidjson::kObjectType);
        aggregator.init(&doc.GetAllocator());
        aggregator.add(get_config("ASM", update));
        aggregator.aggregate(doc);

        const auto &overrides = doc["rules_override"];
        EXPECT_TRUE(overrides.IsArray());
        EXPECT_EQ(overrides.Size(), 0);

        const auto &exclusions = doc["exclusions"];
        EXPECT_TRUE(exclusions.IsArray());
        EXPECT_EQ(exclusions.Size(), 1);

        const auto &actions = doc["actions"];
        EXPECT_TRUE(actions.IsArray());
        EXPECT_EQ(actions.Size(), 0);

        const auto &custom_rules = doc["custom_rules"];
        EXPECT_TRUE(custom_rules.IsArray());
        EXPECT_EQ(custom_rules.Size(), 0);
    }
}

TEST(RemoteConfigAsmAggregator, CustomRulesSingleConfig)
{
    const std::string update =
        R"({"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom","category":"custom"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],"on_match":["block"]}]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", update));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 1);
}

TEST(RemoteConfigAsmAggregator, CustomRulesMultipleConfigs)
{
    const std::string update =
        R"({"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom","category":"custom"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],"on_match":["block"]}]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", update));
    {
        const std::string invalid =
            R"({"custom_rules": {"id":"1","name":"custom_rule1","tags":{"type":"custom","category":"custom"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],"on_match":["block"]}})";
        EXPECT_THROW(aggregator.add(get_config("ASM", invalid)),
            remote_config::error_applying_config);
    }
    aggregator.add(get_config("ASM", update));
    aggregator.add(get_config("ASM", update));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 3);
}

TEST(RemoteConfigAsmAggregator, CustomRulesIgnoreInvalidConfigs)
{
    const std::string update =
        R"({"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom","category":"custom"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],"on_match":["block"]}]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", update));
    aggregator.add(get_config("ASM", update));
    aggregator.add(get_config("ASM", update));
    aggregator.add(get_config("ASM", update));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 0);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 0);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 4);
}

TEST(RemoteConfigAsmAggregator, CustomRulesConfigCycling)
{
    const std::string update =
        R"({"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom","category":"custom"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],"on_match":["block"]}]})";

    remote_config::asm_aggregator aggregator;

    {
        rapidjson::Document doc(rapidjson::kObjectType);
        aggregator.init(&doc.GetAllocator());
        aggregator.add(get_config("ASM", update));
        aggregator.add(get_config("ASM", update));
        aggregator.add(get_config("ASM", update));
        aggregator.add(get_config("ASM", update));
        aggregator.aggregate(doc);

        const auto &overrides = doc["rules_override"];
        EXPECT_TRUE(overrides.IsArray());
        EXPECT_EQ(overrides.Size(), 0);

        const auto &exclusions = doc["exclusions"];
        EXPECT_TRUE(exclusions.IsArray());
        EXPECT_EQ(exclusions.Size(), 0);

        const auto &actions = doc["actions"];
        EXPECT_TRUE(actions.IsArray());
        EXPECT_EQ(actions.Size(), 0);

        const auto &custom_rules = doc["custom_rules"];
        EXPECT_TRUE(custom_rules.IsArray());
        EXPECT_EQ(custom_rules.Size(), 4);
    }

    {
        rapidjson::Document doc(rapidjson::kObjectType);
        aggregator.init(&doc.GetAllocator());
        aggregator.add(get_config("ASM", update));
        aggregator.aggregate(doc);

        const auto &overrides = doc["rules_override"];
        EXPECT_TRUE(overrides.IsArray());
        EXPECT_EQ(overrides.Size(), 0);

        const auto &exclusions = doc["exclusions"];
        EXPECT_TRUE(exclusions.IsArray());
        EXPECT_EQ(exclusions.Size(), 0);

        const auto &actions = doc["actions"];
        EXPECT_TRUE(actions.IsArray());
        EXPECT_EQ(actions.Size(), 0);

        const auto &custom_rules = doc["custom_rules"];
        EXPECT_TRUE(custom_rules.IsArray());
        EXPECT_EQ(custom_rules.Size(), 1);
    }
}

TEST(RemoteConfigAsmAggregator, AllSingleConfigs)
{
    const std::string update =
        R"({"rules_override": [{"rules_target": [{"tags": {"confidence": "1"}}], "on_match": ["block"]}],"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}],"actions": [{"id": "redirect", "type": "redirect_request", "parameters": {"status_code": "303", "location": "localhost"}}],"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom","category":"custom"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],"on_match":["block"]}]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM", update));
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 1);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 1);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 1);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 1);
}

TEST(RemoteConfigAsmAggregator, IgnoreInvalidConfigs)
{
    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    {
        const std::string update =
            R"({"rules_override": [{"rules_target": [{"tags": {"confidence": "1"}}], "on_match": ["block"]}]})";
        aggregator.add(get_config("ASM", update));
    }
    {
        const std::string update =
            R"({"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}]})";
        aggregator.add(get_config("ASM", update));
    }
    {
        const std::string update =
            R"({"actions": {"id": "redirect", "type": "redirect_request", "parameters": {"status_code": "303", "location": "localhost"}}})";
        EXPECT_THROW(aggregator.add(get_config("ASM", update)),
            remote_config::error_applying_config);
    }
    {
        const std::string update =
            R"({"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom","category":"custom"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],"on_match":["block"]}]})";
        aggregator.add(get_config("ASM", update));
    }
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 1);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 1);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 1);
}

TEST(RemoteConfigAsmAggregator, IgnoreInvalidOverlappingConfigs)
{
    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    {
        const std::string update =
            R"({"rules_override": [{"rules_target": [{"tags": {"confidence": "1"}}], "on_match": ["block"]}],"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}]})";
        aggregator.add(get_config("ASM", update));
    }
    {
        const std::string update =
            R"({"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}],"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom","category":"custom"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],"on_match":["block"]}]})";
        aggregator.add(get_config("ASM", update));
    }
    {
        const std::string update =
            R"({"rules_override": [{"rules_target": [{"tags": {"confidence": "1"}}], "on_match": ["block"]}],"actions": {"id": "redirect", "type": "redirect_request", "parameters": {"status_code": "303", "location": "localhost"}}})";
        EXPECT_THROW(aggregator.add(get_config("ASM", update)),
            remote_config::error_applying_config);
    }
    {
        const std::string update =
            R"({"custom_rules":{"id":"1","name":"custom_rule1","tags":{"type":"custom","category":"custom"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],"on_match":["block"]}})";
        EXPECT_THROW(aggregator.add(get_config("ASM", update)),
            remote_config::error_applying_config);
    }
    aggregator.aggregate(doc);

    const auto &overrides = doc["rules_override"];
    EXPECT_TRUE(overrides.IsArray());
    EXPECT_EQ(overrides.Size(), 1);

    const auto &exclusions = doc["exclusions"];
    EXPECT_TRUE(exclusions.IsArray());
    EXPECT_EQ(exclusions.Size(), 2);

    const auto &actions = doc["actions"];
    EXPECT_TRUE(actions.IsArray());
    EXPECT_EQ(actions.Size(), 0);

    const auto &custom_rules = doc["custom_rules"];
    EXPECT_TRUE(custom_rules.IsArray());
    EXPECT_EQ(custom_rules.Size(), 1);
}

} // namespace
} // namespace dds::remote_config
