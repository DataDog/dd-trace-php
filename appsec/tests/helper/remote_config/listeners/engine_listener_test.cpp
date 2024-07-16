// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../../common.hpp"
#include "../mocks.hpp"
#include "base64.h"
#include "json_helper.hpp"
#include "metrics.hpp"
#include "remote_config/exception.hpp"
#include "remote_config/listeners/engine_listener.hpp"
#include "remote_config/product.hpp"
#include "subscriber/waf.hpp"
#include <rapidjson/writer.h>

const std::string waf_rule =
    R"({"version":"2.1","rules":[{"id":"1","name":"rule1","tags":{"type":"flow1","category":"category1"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg1","key_path":[]}],"regex":".*"}}]}]})";

namespace dds::remote_config {

using dds::mock::tel_submitter;
using mock::generate_config;

namespace {

ACTION_P(SaveDocument, param)
{
    rapidjson::Document &document =
        *reinterpret_cast<rapidjson::Document *>(param);

    arg0.copy(document);
}
} // namespace

TEST(RemoteConfigEngineListener, NoUpdates)
{
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _)).Times(0);

    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.commit();
}

TEST(RemoteConfigEngineListener, UnknownConfig)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _)).Times(0);

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    EXPECT_THROW(listener.on_update(generate_config("UNKNOWN", waf_rule)),
        error_applying_config);
    listener.commit();
}

TEST(RemoteConfigEngineListener, RuleUpdate)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.on_update(generate_config("ASM_DD", waf_rule));
    listener.commit();

    {
        const auto &it = doc.FindMember("rules");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    std::array<std::string_view, 5> keys = {"rules_override", "exclusions",
        "actions", "custom_rules", "rules_data"};
    for (auto key : keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_EQ(it, doc.MemberEnd());
    }
}

TEST(RemoteConfigEngineListener, RuleUpdateFallback)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    remote_config::engine_listener listener(
        engine, msubmitter, create_sample_rules_ok());
    listener.init();
    listener.on_unapply(generate_config("ASM_DD", waf_rule));
    listener.commit();

    {
        const auto &it = doc.FindMember("rules");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    std::array<std::string_view, 4> keys = {
        "rules_override", "exclusions", "custom_rules", "rules_data"};
    for (auto key : keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_EQ(it, doc.MemberEnd());
    }
}

TEST(RemoteConfigEngineListener, RulesOverrideUpdate)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    const std::string update =
        R"({"rules_override": [{"rules_target": [{"rule_id": "1"}], "enabled":"false"}]})";

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.on_update(generate_config("ASM", update));
    listener.commit();

    {
        const auto &it = doc.FindMember("rules_override");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    std::array<std::string_view, 3> empty_keys = {
        "exclusions", "actions", "custom_rules"};
    for (auto key : empty_keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_EQ(it->value.Size(), 0);
    }

    std::array<std::string_view, 2> unavailable_keys = {"rules", "rules_data"};
    for (auto key : unavailable_keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_EQ(it, doc.MemberEnd());
    }
}

TEST(RemoteConfigEngineListener, RulesAndRulesOverrideUpdate)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    const std::string update =
        R"({"rules_override": [{"rules_target": [{"rule_id": "1"}], "enabled":"false"}]})";

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.on_update(generate_config("ASM_DD", waf_rule));
    listener.on_update(generate_config("ASM", update));
    listener.commit();

    {
        const auto &it = doc.FindMember("rules");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    {
        const auto &it = doc.FindMember("rules_override");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    std::array<std::string_view, 3> empty_keys = {
        "exclusions", "actions", "custom_rules"};
    for (auto key : empty_keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_EQ(it->value.Size(), 0);
    }

    std::array<std::string_view, 1> unavailable_keys = {"rules_data"};
    for (auto key : unavailable_keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_EQ(it, doc.MemberEnd());
    }
}

TEST(RemoteConfigEngineListener, ExclusionsUpdate)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    const std::string update =
        R"({"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}]})";

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.on_update(generate_config("ASM", update));
    listener.commit();

    {
        const auto &it = doc.FindMember("exclusions");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    // Rules aren't present if there are no updates
    std::array<std::string_view, 3> keys = {
        "rules_override", "actions", "custom_rules"};
    for (auto key : keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_EQ(it->value.Size(), 0);
    }

    std::array<std::string_view, 2> unavailable_keys = {"rules", "rules_data"};
    for (auto key : unavailable_keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_EQ(it, doc.MemberEnd());
    }
}

TEST(RemoteConfigEngineListener, RulesAndExclusionsUpdate)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    const std::string update =
        R"({"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}]})";

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.on_update(generate_config("ASM_DD", waf_rule));
    listener.on_update(generate_config("ASM", update));
    listener.commit();

    {
        const auto &it = doc.FindMember("rules");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    {
        const auto &it = doc.FindMember("exclusions");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    std::array<std::string_view, 3> keys = {
        "rules_override", "actions", "custom_rules"};
    for (auto key : keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_EQ(it->value.Size(), 0);
    }

    std::array<std::string_view, 1> unavailable_keys = {"rules_data"};
    for (auto key : unavailable_keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_EQ(it, doc.MemberEnd());
    }
}

TEST(RemoteConfigEngineListener, ActionsUpdate)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    const std::string update =
        R"({"actions": [{"id": "redirect", "type": "redirect_request", "parameters":
            {"status_code": "303", "location": "localhost"}}]})";

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.on_update(generate_config("ASM", update));
    listener.commit();

    {
        const auto &it = doc.FindMember("actions");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    // Rules aren't present if there are no updates
    std::array<std::string_view, 3> keys = {
        "rules_override", "exclusions", "custom_rules"};
    for (auto key : keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_EQ(it->value.Size(), 0);
    }

    std::array<std::string_view, 2> unavailable_keys = {"rules", "rules_data"};
    for (auto key : unavailable_keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_EQ(it, doc.MemberEnd());
    }
}

TEST(RemoteConfigEngineListener, RulesAndActionsUpdate)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    const std::string update =
        R"({"actions": [{"id": "redirect", "type": "redirect_request", "parameters":
            {"status_code": "303", "location": "localhost"}}]})";

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.on_update(generate_config("ASM_DD", waf_rule));
    listener.on_update(generate_config("ASM", update));
    listener.commit();

    {
        const auto &it = doc.FindMember("rules");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    {
        const auto &it = doc.FindMember("actions");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    std::array<std::string_view, 3> keys = {
        "rules_override", "exclusions", "custom_rules"};
    for (auto key : keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_EQ(it->value.Size(), 0);
    }

    std::array<std::string_view, 1> unavailable_keys = {"rules_data"};
    for (auto key : unavailable_keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_EQ(it, doc.MemberEnd());
    }
}

TEST(RemoteConfigEngineListener, CustomRulesUpdate)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    const std::string update =
        R"({"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom",
            "category":"custom"},"conditions":[{"operator":"match_regex","parameters":
            {"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],
            "on_match":["block"]}]})";

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.on_update(generate_config("ASM", update));
    listener.commit();

    {
        const auto &it = doc.FindMember("custom_rules");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    // Rules aren't present if there are no updates
    std::array<std::string_view, 3> keys = {
        "rules_override", "exclusions", "actions"};
    for (auto key : keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_EQ(it->value.Size(), 0);
    }

    std::array<std::string_view, 2> unavailable_keys = {"rules", "rules_data"};
    for (auto key : unavailable_keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_EQ(it, doc.MemberEnd());
    }
}

TEST(RemoteConfigEngineListener, RulesAndCustomRulesUpdate)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    const std::string update =
        R"({"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom",
            "category":"custom"},"conditions":[{"operator":"match_regex","parameters":
            {"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],
            "on_match":["block"]}]})";

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.on_update(generate_config("ASM_DD", waf_rule));
    listener.on_update(generate_config("ASM", update));
    listener.commit();

    {
        const auto &it = doc.FindMember("rules");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    {
        const auto &it = doc.FindMember("custom_rules");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    std::array<std::string_view, 3> keys = {
        "rules_override", "exclusions", "actions"};
    for (auto key : keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_EQ(it->value.Size(), 0);
    }

    std::array<std::string_view, 1> unavailable_keys = {"rules_data"};
    for (auto key : unavailable_keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_EQ(it, doc.MemberEnd());
    }
}

TEST(RemoteConfigEngineListener, RulesDataUpdate)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    const std::string update =
        R"({"rules_data":[{"id":"blocked_ips","type":"ip_with_expiration","data":[{"value":"1.2.3.4","expiration":0}]}]})";

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.on_update(generate_config("ASM_DATA", update));
    listener.commit();

    {
        const auto &it = doc.FindMember("rules_data");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    // Rules aren't present if there are no updates
    std::array<std::string_view, 5> keys = {
        "rules", "rules_override", "exclusions", "actions", "custom_rules"};
    for (auto key : keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_EQ(it, doc.MemberEnd());
    }
}

TEST(RemoteConfigEngineListener, RulesAndRuleDataUpdate)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    const std::string update =
        R"({"rules_data":[{"id":"blocked_ips","type":"ip_with_expiration","data":[{"value":"1.2.3.4","expiration":0}]}]})";

    remote_config::engine_listener listener(engine, msubmitter);

    listener.init();
    listener.on_update(generate_config("ASM_DD", waf_rule));
    listener.on_update(generate_config("ASM_DATA", update));
    listener.commit();

    {
        const auto &it = doc.FindMember("rules");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    {
        const auto &it = doc.FindMember("rules_data");
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }

    std::array<std::string_view, 4> keys = {
        "rules_override", "exclusions", "actions", "custom_rules"};
    for (auto key : keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_EQ(it, doc.MemberEnd());
    }
}

TEST(RemoteConfigEngineListener, FullUpdate)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.on_update(generate_config("ASM_DD", waf_rule));
    {
        const std::string update =
            R"({"rules_data":[{"id":"blocked_ips","type":"ip_with_expiration","data":[{"value":"1.2.3.4","expiration":0}]}]})";
        listener.on_update(generate_config("ASM_DATA", update));
    }
    {
        const std::string update =
            R"({"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom",
                "category":"custom"},"conditions":[{"operator":"match_regex","parameters":
                {"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],
                "on_match":["block"]}]})";

        listener.on_update(generate_config("ASM", update));
    }
    {
        const std::string update =
            R"({"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}]})";
        listener.on_update(generate_config("ASM", update));
    }
    {
        const std::string update =
            R"({"actions": [{"id": "redirect", "type": "redirect_request", "parameters":
                {"status_code": "303", "location": "localhost"}}]})";
        listener.on_update(generate_config("ASM", update));
    }
    {
        const std::string update =
            R"({"rules_override": [{"rules_target": [{"rule_id": "1"}], "enabled":"false"}]})";
        listener.on_update(generate_config("ASM", update));
    }
    listener.commit();

    std::array<std::string_view, 6> keys = {"rules", "rules_override",
        "exclusions", "actions", "custom_rules", "rules_data"};
    for (auto key : keys) {
        const auto &it = doc.FindMember(StringRef(key));
        ASSERT_NE(it, doc.MemberEnd());
        EXPECT_TRUE(it->value.IsArray());
        EXPECT_GT(it->value.Size(), 0);
    }
}

TEST(RemoteConfigEngineListener, MultipleInitCommitUpdates)
{
    auto msubmitter =
        std::shared_ptr<metrics::TelemetrySubmitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(3)
        .WillRepeatedly(DoAll(SaveDocument(&doc)));

    remote_config::engine_listener listener(
        engine, msubmitter, create_sample_rules_ok());

    listener.init();
    listener.on_update(generate_config("ASM_DD", waf_rule));
    {
        const std::string update =
            R"({"rules_data":[{"id":"blocked_ips","type":"ip_with_expiration","data":[{"value":"1.2.3.4","expiration":0}]}]})";
        listener.on_update(generate_config("ASM_DATA", update));
    }
    listener.commit();

    {
        {
            const auto &it = doc.FindMember("rules");
            ASSERT_NE(it, doc.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
            EXPECT_GT(it->value.Size(), 0);
        }

        {
            const auto &it = doc.FindMember("rules_data");
            ASSERT_NE(it, doc.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
            EXPECT_GT(it->value.Size(), 0);
        }

        std::array<std::string_view, 4> keys = {
            "rules_override", "exclusions", "actions", "custom_rules"};
        for (auto key : keys) {
            const auto &it = doc.FindMember(StringRef(key));
            ASSERT_EQ(it, doc.MemberEnd());
        }
    }

    listener.init();
    {
        const std::string update =
            R"({"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom",
                "category":"custom"},"conditions":[{"operator":"match_regex","parameters":
                {"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],
                "on_match":["block"]}]})";

        listener.on_update(generate_config("ASM", update));
    }
    {
        const std::string update =
            R"({"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}]})";
        listener.on_update(generate_config("ASM", update));
    }
    listener.commit();

    {
        {
            const auto &it = doc.FindMember("custom_rules");
            ASSERT_NE(it, doc.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
            EXPECT_GT(it->value.Size(), 0);
        }

        {
            const auto &it = doc.FindMember("exclusions");
            ASSERT_NE(it, doc.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
            EXPECT_GT(it->value.Size(), 0);
        }

        std::array<std::string_view, 2> keys = {"rules_override", "actions"};
        for (auto key : keys) {
            const auto &it = doc.FindMember(StringRef(key));
            ASSERT_NE(it, doc.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
            EXPECT_EQ(it->value.Size(), 0);
        }

        std::array<std::string_view, 2> unavailable_keys = {
            "rules", "rules_data"};
        for (auto key : unavailable_keys) {
            const auto &it = doc.FindMember(StringRef(key));
            ASSERT_EQ(it, doc.MemberEnd());
        }
    }

    listener.init();
    listener.on_update(generate_config("ASM_DD", waf_rule));
    {
        const std::string update =
            R"({"actions": [{"id": "redirect", "type": "redirect_request", "parameters":
                {"status_code": "303", "location": "localhost"}}]})";
        listener.on_update(generate_config("ASM", update));
    }
    {
        const std::string update =
            R"({"rules_override": [{"rules_target": [{"rule_id": "1"}], "enabled":"false"}]})";
        listener.on_update(generate_config("ASM", update));
    }
    listener.commit();

    {
        {
            const auto &it = doc.FindMember("rules");
            ASSERT_NE(it, doc.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
            EXPECT_GT(it->value.Size(), 0);
        }

        {
            const auto &it = doc.FindMember("rules_override");
            ASSERT_NE(it, doc.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
            EXPECT_GT(it->value.Size(), 0);
        }

        {
            const auto &it = doc.FindMember("actions");
            ASSERT_NE(it, doc.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
            EXPECT_GT(it->value.Size(), 0);
        }

        std::array<std::string_view, 2> keys = {"exclusions", "custom_rules"};
        for (auto key : keys) {
            const auto &it = doc.FindMember(StringRef(key));
            ASSERT_NE(it, doc.MemberEnd());
            EXPECT_TRUE(it->value.IsArray());
            EXPECT_EQ(it->value.Size(), 0);
        }

        std::array<std::string_view, 1> unavailable_keys = {"rules_data"};
        for (auto key : unavailable_keys) {
            const auto &it = doc.FindMember(StringRef(key));
            ASSERT_EQ(it, doc.MemberEnd());
        }
    }
}

TEST(RemoteConfigEngineListener, EngineRuleUpdate)
{
    const std::string rules =
        R"({"version": "2.2", "rules": [{"id": "some id", "name": "some name", "tags":
            {"type": "lfi", "category": "attack_attempt"}, "conditions": [{"parameters":
            {"inputs": [{"address": "server.request.query"} ], "list": ["/other/url"] },
            "operator": "phrase_match"} ], "on_match": ["block"] } ] })";

    auto msubmitter = std::shared_ptr<metrics::TelemetrySubmitter>(
        new NiceMock<tel_submitter>());
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;
    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(rules, *msubmitter));

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("server.request.query", parameter::string("/anotherUrl"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    const std::string new_rules =
        R"({"version": "2.2", "rules": [{"id": "some id", "name": "some name","tags":
            {"type": "lfi", "category": "attack_attempt"}, "conditions":[{"parameters":
            {"inputs": [{"address": "server.request.query"} ], "list":
            ["/anotherUrl"] }, "operator": "phrase_match"} ], "on_match": ["block"]}]})";

    remote_config::engine_listener listener(e, msubmitter);
    listener.init();
    listener.on_update(generate_config("ASM_DD", new_rules));
    listener.commit();

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("server.request.query", parameter::string("/anotherUrl"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::block);
        EXPECT_EQ(res->events.size(), 1);
    }
}

TEST(RemoteConfigEngineListener, EngineRuleUpdateFallback)
{
    const std::string rules =
        R"({"version": "2.2", "rules": [{"id": "some id", "name": "some name", "tags":
            {"type": "lfi", "category": "attack_attempt"}, "conditions": [{"parameters":
            {"inputs": [{"address": "server.request.query"} ], "list": ["/a/url"] },
            "operator": "phrase_match"} ], "on_match": ["block"] } ] })";

    auto msubmitter = std::shared_ptr<metrics::TelemetrySubmitter>(
        new NiceMock<tel_submitter>());

    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;
    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(rules, *msubmitter));

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("server.request.query", parameter::string("/a/url"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::block);
        EXPECT_EQ(res->events.size(), 1);
    }

    remote_config::engine_listener listener(
        e, msubmitter, create_sample_rules_ok());
    listener.init();
    listener.on_unapply(generate_config("ASM_DD", ""));
    listener.commit();

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("server.request.query", parameter::string("/a/url"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }
}

TEST(RemoteConfigEngineListener, EngineRuleOverrideUpdateDisableRule)
{
    auto msubmitter = std::shared_ptr<metrics::TelemetrySubmitter>(
        new NiceMock<tel_submitter>());

    auto engine{dds::engine::create()};
    engine->subscribe(waf::instance::from_string(waf_rule, *msubmitter));

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
    }

    const std::string rule_override =
        R"({"rules_override": [{"rules_target": [{"rule_id": "1"}], "enabled":"false"}]})";
    listener.on_update(generate_config("ASM", rule_override));

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
    }

    listener.commit();
    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }
}

TEST(RemoteConfigEngineListener, RuleOverrideUpdateSetOnMatch)
{
    auto msubmitter = std::shared_ptr<metrics::TelemetrySubmitter>(
        new NiceMock<tel_submitter>());

    auto engine{dds::engine::create()};
    engine->subscribe(waf::instance::from_string(waf_rule, *msubmitter));

    remote_config::engine_listener listener(engine, msubmitter);

    listener.init();

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }

    const std::string rule_override =
        R"({"rules_override": [{"rules_target": [{"tags": {"type": "flow1"}}], "on_match": ["block"]}]})";
    listener.on_update(generate_config("ASM", rule_override));

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }

    listener.commit();
    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::block);
    }
}

TEST(RemoteConfigEngineListener, EngineRuleOverrideAndActionsUpdate)
{
    auto msubmitter = std::shared_ptr<metrics::TelemetrySubmitter>(
        new NiceMock<tel_submitter>());

    auto engine{dds::engine::create()};
    engine->subscribe(waf::instance::from_string(waf_rule, *msubmitter));

    remote_config::engine_listener listener(engine, msubmitter);

    listener.init();

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }
    const std::string update =
        R"({"actions": [{"id": "redirect", "type": "redirect_request", "parameters":
            {"status_code": "303", "location": "localhost"}}],"rules_override":
            [{"rules_target": [{"rule_id": "1"}], "on_match": ["redirect"]}]})";

    listener.on_update(generate_config("ASM", update));

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }

    listener.commit();
    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::redirect);
    }
}

TEST(RemoteConfigEngineListener, EngineExclusionsUpdatePasslistRule)
{
    auto msubmitter = std::shared_ptr<metrics::TelemetrySubmitter>(
        new NiceMock<tel_submitter>());

    auto engine{dds::engine::create()};
    engine->subscribe(waf::instance::from_string(waf_rule, *msubmitter));

    remote_config::engine_listener listener(engine, msubmitter);

    listener.init();

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
    }

    const std::string update =
        R"({"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}]})";
    listener.on_update(generate_config("ASM", update));

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
    }

    listener.commit();
    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }
}

TEST(RemoteConfigEngineListener, EngineCustomRulesUpdate)
{
    auto msubmitter = std::shared_ptr<metrics::TelemetrySubmitter>(
        new NiceMock<tel_submitter>());

    auto engine{dds::engine::create()};
    engine->subscribe(waf::instance::from_string(waf_rule, *msubmitter));

    remote_config::engine_listener listener(engine, msubmitter);

    listener.init();

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
    }

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg3", parameter::string("custom rule"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    const std::string update =
        R"({"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom",
            "category":"custom"},"conditions":[{"operator":"match_regex","parameters":
            {"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],
            "on_match":["block"]}]})";
    listener.on_update(generate_config("ASM", update));

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
    }

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg3", parameter::string("custom rule"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    listener.commit();
    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
    }

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg3", parameter::string("custom rule"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
    }

    listener.init();
    listener.on_update(generate_config("ASM", R"({"custom_rules":[]})"));
    listener.commit();

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
    }

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg3", parameter::string("custom rule"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }
}

TEST(RemoteConfigEngineListener, EngineRuleDataUpdate)
{
    const std::string waf_rule_with_data =
        R"({"version":"2.1","rules":[{"id":"blk-001-001","name":"Block IP Addresses",
            "tags":{"type":"block_ip","category":"security_response"},"conditions":
            [{"parameters":{"inputs":[{"address":"http.client_ip"}],"data":"blocked_ips"},
            "operator":"ip_match"}],"transformers":[],"on_match":["block"]}]})";

    auto msubmitter = std::shared_ptr<metrics::TelemetrySubmitter>(
        new NiceMock<tel_submitter>());
    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule_with_data, *msubmitter));

    remote_config::engine_listener listener(e, msubmitter);
    listener.init();

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("1.2.3.4"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    const std::string update =
        R"({"rules_data":[{"id":"blocked_ips","type":"ip_with_expiration","data":[{"value":"1.2.3.4","expiration":0}]}]})";
    listener.on_update(generate_config("ASM_DATA", update));
    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("1.2.3.4"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    listener.commit();
    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("1.2.3.4"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::block);
        EXPECT_EQ(res->events.size(), 1);
    }
}

} // namespace dds::remote_config
