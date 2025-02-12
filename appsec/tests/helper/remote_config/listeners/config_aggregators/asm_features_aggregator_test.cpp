// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../../mocks.hpp"
#include "remote_config/exception.hpp"
#include "remote_config/listeners/config_aggregators/asm_features_aggregator.hpp"
#include <rapidjson/document.h>

namespace dds::remote_config {
namespace {

using mock::get_config;

ACTION_P(SaveDocument, param)
{
    rapidjson::Document &document =
        *reinterpret_cast<rapidjson::Document *>(param);

    arg0.copy(document);
}

TEST(RemoteConfigAsmFeaturesAggregator, EmptyCommit)
{
    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 0);
}

TEST(RemoteConfigAsmFeaturesAggregator, EmptyConfigThrows)
{
    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);

    aggregator.init(&doc.GetAllocator());
    EXPECT_THROW(aggregator.add(get_config("ASM_FEATURES", {})),
        std::runtime_error); // mmap failure

    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 0);
}

TEST(RemoteConfigAsmFeaturesAggregator, InvalidJson)
{
    const std::string rule_override = R"({"asm": [])";

    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(aggregator.add(get_config("ASM_FEATURES", rule_override)),
        remote_config::error_applying_config);

    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 0);
}

TEST(RemoteConfigAsmFeaturesAggregator, IncorrectTypeThrows)
{
    const std::string rule_override = R"({"asm": []})";

    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(aggregator.add(get_config("ASM_FEATURES", rule_override)),
        remote_config::error_applying_config);

    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 0);
}

TEST(RemoteConfigAsmFeaturesAggregator, InvalidKeyName)
{
    const std::string rule_override = R"({"asn": { "enabled": true }})";

    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 0);
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesAsmEmpty)
{
    const std::string rule_override = R"({"asm": {}})";

    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 1);

    const auto &key = doc["asm"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 0);
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesAsmSingleConfig)
{
    const std::string rule_override = R"({"asm": { "enabled": true }})";

    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 1);

    const auto &key = doc["asm"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 1);

    const auto &enabled = key["enabled"];
    EXPECT_TRUE(enabled.IsBool());
    EXPECT_TRUE(enabled.GetBool());
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesInstrumModeEmpty)
{
    const std::string rule_override = R"({"auto_user_instrum": {}})";

    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 1);

    const auto &key = doc["auto_user_instrum"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 0);
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesInstrumModeSingleConfig)
{
    const std::string rule_override =
        R"({"auto_user_instrum": { "mode": "identification" }})";

    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 1);

    const auto &key = doc["auto_user_instrum"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 1);

    const auto &mode = key["mode"];
    EXPECT_TRUE(mode.IsString());
    EXPECT_EQ(mode.GetString(), "identification"sv);
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesAttackModeEmpty)
{
    const std::string rule_override = R"({"attack_mode": {}})";

    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 1);

    const auto &key = doc["attack_mode"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 0);
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesAttackModeSingleConfig)
{
    const std::string rule_override =
        R"({"attack_mode": { "isAttackModeEnabled": true, "enabledServices": [] }})";

    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 1);

    const auto &key = doc["attack_mode"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 2);

    const auto &enabled = key["isAttackModeEnabled"];
    EXPECT_TRUE(enabled.IsBool());
    EXPECT_TRUE(enabled.GetBool());

    const auto &services = key["enabledServices"];
    EXPECT_TRUE(services.IsArray());
    EXPECT_TRUE(services.Empty());
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesApiSecurityEmpty)
{
    const std::string rule_override = R"({"api_security": {}})";

    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 1);

    const auto &key = doc["api_security"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 0);
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesApiSecuritySingleConfig)
{
    const std::string rule_override =
        R"({"api_security": { "request_sample_rate": 0.1 }})";

    remote_config::asm_features_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 1);

    const auto &key = doc["api_security"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 1);

    const auto &rate = key["request_sample_rate"];
    EXPECT_TRUE(rate.IsFloat());
    EXPECT_EQ(rate.GetFloat(), 0.1f);
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesOverrideSameConfig)
{
    const std::string rule_override = R"({"asm": { "enabled": true }})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_features_aggregator aggregator;
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 1);

    const auto &key = doc["asm"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 1);

    const auto &enabled = key["enabled"];
    EXPECT_TRUE(enabled.IsBool());
    EXPECT_TRUE(enabled.GetBool());
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesOverrideDifferentConfig)
{
    const std::string rule_override = R"({"asm": { "enabled": true }})";
    const std::string second_rule_override = R"({"asm": { "enabled": false }})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_features_aggregator aggregator;
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.add(get_config("ASM_FEATURES", second_rule_override));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 1);

    const auto &key = doc["asm"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 1);

    const auto &enabled = key["enabled"];
    EXPECT_TRUE(enabled.IsBool());
    EXPECT_FALSE(enabled.GetBool());
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesOverrideIgnoreInvalidConfigs)
{
    const std::string rule_override = R"({"asm": { "enabled": true }})";
    const std::string invalid = R"({"asm": [{ "enabled": false }]})";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_features_aggregator aggregator;
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rule_override));

    EXPECT_THROW(aggregator.add(get_config("ASM_FEATURES", invalid)),
        remote_config::error_applying_config);

    aggregator.add(get_config("ASM_FEATURES", rule_override));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 1);

    const auto &key = doc["asm"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 1);

    const auto &enabled = key["enabled"];
    EXPECT_TRUE(enabled.IsBool());
    EXPECT_TRUE(enabled.GetBool());
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesCustomConfigIgnoreInvalidKey)
{
    const std::string rules =
        R"({"asm": { "enabled": true }, "invalid": false })";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_features_aggregator aggregator;
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_config("ASM_FEATURES", rules));
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 1);

    const auto &key = doc["asm"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 1);

    const auto &enabled = key["enabled"];
    EXPECT_TRUE(enabled.IsBool());
    EXPECT_TRUE(enabled.GetBool());
}

TEST(RemoteConfigAsmFeaturesAggregator, RulesCustomConfigInvalidType)
{
    const std::string rules =
        R"({"asm": { "enabled": true }, "auto_user_instrum": false })";

    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_features_aggregator aggregator;
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(aggregator.add(get_config("ASM_FEATURES", rules)),
        remote_config::error_applying_config);

    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 0);
}

TEST(RemoteConfigAsmFeaturesAggregator, AllSingleConfigs)
{
    rapidjson::Document doc(rapidjson::kObjectType);
    remote_config::asm_features_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());
    {
        const std::string rules = R"({"asm": { "enabled": true }})";

        aggregator.add(get_config("ASM_FEATURES", rules));
    }
    {
        const std::string rules =
            R"({"auto_user_instrum": { "mode": "identification"}})";

        aggregator.add(get_config("ASM_FEATURES", rules));
    }
    {
        const std::string rules =
            R"({"attack_mode": { "isAttackModeEnabled": true, "enabledServices": []}})";

        aggregator.add(get_config("ASM_FEATURES", rules));
    }
    {
        const std::string rules =
            R"({"api_security": { "request_sample_rate": 0.1}})";

        aggregator.add(get_config("ASM_FEATURES", rules));
    }
    aggregator.aggregate(doc);

    EXPECT_TRUE(doc.IsObject());
    EXPECT_EQ(doc.MemberCount(), 4);

    const auto &key = doc["asm"];
    EXPECT_TRUE(key.IsObject());
    EXPECT_EQ(key.MemberCount(), 1);

    const auto &enabled = key["enabled"];
    EXPECT_TRUE(enabled.IsBool());
    EXPECT_TRUE(enabled.GetBool());

    const auto &auto_user_instrum = doc["auto_user_instrum"];
    EXPECT_TRUE(auto_user_instrum.IsObject());
    EXPECT_EQ(auto_user_instrum.MemberCount(), 1);

    const auto &mode = auto_user_instrum["mode"];
    EXPECT_TRUE(mode.IsString());
    EXPECT_EQ(mode.GetString(), "identification"sv);

    const auto &attack_mode = doc["attack_mode"];
    EXPECT_TRUE(attack_mode.IsObject());
    EXPECT_EQ(attack_mode.MemberCount(), 2);

    const auto &mode_enabled = attack_mode["isAttackModeEnabled"];
    EXPECT_TRUE(mode_enabled.IsBool());
    EXPECT_TRUE(mode_enabled.GetBool());

    const auto &services = attack_mode["enabledServices"];
    EXPECT_TRUE(services.IsArray());
    EXPECT_TRUE(services.Empty());

    const auto &api_security = doc["api_security"];
    EXPECT_TRUE(api_security.IsObject());
    EXPECT_EQ(api_security.MemberCount(), 1);

    const auto &rate = api_security["request_sample_rate"];
    EXPECT_TRUE(rate.IsFloat());
    EXPECT_EQ(rate.GetFloat(), 0.1f);
}

} // namespace
} // namespace dds::remote_config
