// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../../common.hpp"
#include "../mocks.hpp"
#include "engine.hpp"
#include "json_helper.hpp"
#include "metrics.hpp"
#include "remote_config/exception.hpp"
#include "remote_config/listeners/engine_listener.hpp"
#include "subscriber/waf.hpp"
#include <memory>
#include <rapidjson/document.h>
#include <rapidjson/writer.h>

const std::string waf_rule =
    R"({"version":"2.1","rules":[{"id":"1","name":"rule1","tags":{"type":"flow1","category":"category1"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg1","key_path":[]}],"regex":".*"}}]},{"id":"2","name":"rule2","tags":{"type":"flow2","category":"category2"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"dummy","key_path":[]}],"regex":".*"}}]}]})";

namespace dds::remote_config {

using dds::mock::tel_submitter;
using mock::get_config;

namespace {

ACTION_P(SaveDocument, param)
{
    rapidjson::Document &document =
        *reinterpret_cast<rapidjson::Document *>(param);
    document.CopyFrom(arg0, document.GetAllocator());
}

rapidjson::Value *find(
    rapidjson::Document &doc, const std::vector<std::string_view> &keys)
{
    rapidjson::Value *v = &doc;
    for (const auto &key : keys) {
        if (key == "<first>" && v->IsObject() && v->MemberCount() > 0) {
            v = &v->MemberBegin()->value;
            continue;
        }
        const auto &it = v->FindMember(StringRef(key));
        if (it == v->MemberEnd()) {
            return nullptr;
        }
        v = &it->value;
    }

    return v;
}
} // namespace

TEST(RemoteConfigEngineListener, NoUpdates)
{
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _)).Times(0);

    auto msubmitter =
        std::shared_ptr<telemetry::telemetry_submitter>(new tel_submitter());
    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.commit();
}

TEST(RemoteConfigEngineListener, UnknownConfig)
{
    auto msubmitter =
        std::shared_ptr<telemetry::telemetry_submitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _)).Times(0);

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    EXPECT_THROW(listener.on_update(get_config("UNKNOWN", waf_rule)),
        error_applying_config);
    listener.commit();
}

TEST(RemoteConfigEngineListener, RuleUpdate)
{
    auto msubmitter =
        std::shared_ptr<telemetry::telemetry_submitter>(new tel_submitter());
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();
    listener.on_update(get_config("ASM_DD", waf_rule));
    listener.commit();

    {
        auto *v = find(doc, {"asm_added", "<first>", "rules"});
        ASSERT_NE(v, nullptr);
        ASSERT_TRUE(v->IsArray());
        EXPECT_GT(v->Size(), 0);
    }
}

TEST(RemoteConfigEngineListener, EngineRuleUpdate)
{
    auto msubmitter = std::shared_ptr<telemetry::telemetry_submitter>(
        new NiceMock<tel_submitter>());
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;
    std::shared_ptr<engine> e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule, *msubmitter));

    const std::string rules =
        R"({"version": "2.2", "rules": [{"id": "some id", "name": "some name", "tags":
        {"type": "lfi", "category": "attack_attempt"}, "conditions": [{"parameters":
        {"inputs": [{"address": "server.request.query"} ], "list": ["/other/url"] },
        "operator": "phrase_match"} ], "on_match": ["block"] } ] })";

    remote_config::config orig_cfg = get_config("ASM_DD", rules);
    {
        remote_config::engine_listener listener(e, msubmitter);
        listener.init();
        listener.on_update(orig_cfg);
        listener.commit();
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("server.request.query", parameter::string("/anotherUrl"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_FALSE(res);
    }

    const std::string new_rules =
        R"({"version": "2.2", "rules": [{"id": "some id", "name": "some name","tags":
            {"type": "lfi", "category": "attack_attempt"}, "conditions":[{"parameters":
            {"inputs": [{"address": "server.request.query"} ], "list":
            ["/anotherUrl"] }, "operator": "phrase_match"} ], "on_match": ["block"]}]})";

    remote_config::engine_listener listener(e, msubmitter);
    listener.init();
    listener.on_unapply(orig_cfg);
    listener.on_update(get_config("ASM_DD", new_rules));
    listener.commit();

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("server.request.query", parameter::string("/anotherUrl"sv));

        auto res = ctx.publish(std::move(p), {});
        ASSERT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::block);
        EXPECT_EQ(res->triggers.size(), 1);
    }
}

TEST(RemoteConfigEngineListener, EngineRuleUpdateFallback)
{
    auto msubmitter = std::shared_ptr<telemetry::telemetry_submitter>(
        new NiceMock<tel_submitter>());

    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;
    std::shared_ptr<engine> e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule, *msubmitter));

    const std::string rules =
        R"({"version": "2.2", "rules": [{"id": "some id", "name": "some name", "tags":
            {"type": "lfi", "category": "attack_attempt"}, "conditions": [{"parameters":
            {"inputs": [{"address": "server.request.query"} ], "list": ["/a/url"] },
            "operator": "phrase_match"} ], "on_match": ["block"] } ] })";

    remote_config::config orig_cfg = get_config("ASM_DD", rules);
    {
        remote_config::engine_listener listener(e, msubmitter);
        listener.init();
        listener.on_update(orig_cfg);
        listener.commit();
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("server.request.query", parameter::string("/a/url"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::block);
        EXPECT_EQ(res->triggers.size(), 1);
    }

    remote_config::engine_listener listener(
        e, msubmitter, create_sample_rules_ok());
    listener.init();
    listener.on_unapply(orig_cfg);
    listener.commit(); // goes back to original rules

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("server.request.query", parameter::string("/a/url"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_FALSE(res);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
    }
}

TEST(RemoteConfigEngineListener, EngineRuleOverrideUpdateDisableRule)
{
    auto msubmitter = std::shared_ptr<telemetry::telemetry_submitter>(
        new NiceMock<tel_submitter>());

    std::shared_ptr engine{dds::engine::create()};
    engine->subscribe(waf::instance::from_string(waf_rule, *msubmitter));

    remote_config::engine_listener listener(engine, msubmitter);
    listener.init();

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
    }

    const std::string rule_override =
        R"({"rules_override": [{"rules_target": [{"rule_id": "1"}], "enabled":"false"}]})";
    listener.on_update(get_config("ASM", rule_override));

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
    }

    listener.commit();
    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_FALSE(res);
    }
}

TEST(RemoteConfigEngineListener, RuleOverrideUpdateSetOnMatch)
{
    auto msubmitter = std::shared_ptr<telemetry::telemetry_submitter>(
        new NiceMock<tel_submitter>());

    std::shared_ptr engine{dds::engine::create()};
    engine->subscribe(waf::instance::from_string(waf_rule, *msubmitter));

    remote_config::engine_listener listener(engine, msubmitter);

    listener.init();

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }

    const std::string rule_override =
        R"({"rules_override": [{"rules_target": [{"tags": {"type": "flow1"}}], "on_match": ["block"]}]})";
    listener.on_update(get_config("ASM", rule_override));

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }

    listener.commit();
    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::block);
    }
}

TEST(RemoteConfigEngineListener, EngineRuleOverrideAndActionsUpdate)
{
    auto msubmitter = std::shared_ptr<telemetry::telemetry_submitter>(
        new NiceMock<tel_submitter>());

    std::shared_ptr engine{dds::engine::create()};
    engine->subscribe(waf::instance::from_string(waf_rule, *msubmitter));

    remote_config::engine_listener listener(engine, msubmitter);

    listener.init();

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }
    const std::string update =
        R"({"actions": [{"id": "redirect", "type": "redirect_request", "parameters":
            {"status_code": "303", "location": "https://localhost"}}],"rules_override":
            [{"rules_target": [{"rule_id": "1"}], "on_match": ["redirect"]}]})";

    listener.on_update(get_config("ASM", update));

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }

    listener.commit();
    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::redirect);
    }
}

TEST(RemoteConfigEngineListener, EngineExclusionsUpdatePasslistRule)
{
    auto msubmitter = std::shared_ptr<telemetry::telemetry_submitter>(
        new NiceMock<tel_submitter>());

    std::shared_ptr engine{dds::engine::create()};
    engine->subscribe(waf::instance::from_string(waf_rule, *msubmitter));

    remote_config::engine_listener listener(engine, msubmitter);

    listener.init();

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
    }

    const std::string update =
        R"({"exclusions":[{"id":1,"rules_target":[{"rule_id":1}]}]})";
    listener.on_update(get_config("ASM", update));

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
    }

    listener.commit();
    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_FALSE(res);
    }
}

TEST(RemoteConfigEngineListener, EngineCustomRulesUpdate)
{
    auto msubmitter = std::shared_ptr<telemetry::telemetry_submitter>(
        new NiceMock<tel_submitter>());

    std::shared_ptr engine{dds::engine::create()};
    engine->subscribe(waf::instance::from_string(waf_rule, *msubmitter));

    remote_config::engine_listener listener(engine, msubmitter);

    listener.init();

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
    }

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg3", parameter::string("custom rule"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_FALSE(res);
    }

    const std::string update =
        R"({"custom_rules":[{"id":"3","name":"custom_rule1","tags":{"type":"custom",
            "category":"custom"},"conditions":[{"operator":"match_regex","parameters":
            {"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],
            "on_match":["block"]}]})";
    auto custom_rules_cfg = get_config("ASM", update);
    listener.on_update(custom_rules_cfg);

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
    }

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg3", parameter::string("custom rule"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_FALSE(res);
    }

    listener.commit();
    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
    }

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg3", parameter::string("custom rule"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
    }

    listener.init();
    listener.on_unapply(custom_rules_cfg);
    listener.on_update(get_config("ASM", R"({"custom_rules":[]})"));
    listener.commit();

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("value"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
    }

    {
        auto ctx = engine->get_context();

        auto p = parameter::map();
        p.add("arg3", parameter::string("custom rule"sv));

        auto res = ctx.publish(std::move(p), {});
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

    auto msubmitter = std::shared_ptr<telemetry::telemetry_submitter>(
        new NiceMock<tel_submitter>());
    std::shared_ptr<engine> e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule_with_data, *msubmitter));

    remote_config::engine_listener listener(e, msubmitter);
    listener.init();

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("1.2.3.4"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_FALSE(res);
    }

    const std::string update =
        R"({"rules_data":[{"id":"blocked_ips","type":"ip_with_expiration","data":[{"value":"1.2.3.4","expiration":0}]}]})";
    listener.on_update(get_config("ASM_DATA", update));
    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("1.2.3.4"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_FALSE(res);
    }

    listener.commit();
    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("1.2.3.4"sv));

        auto res = ctx.publish(std::move(p), {});
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::block);
        EXPECT_EQ(res->triggers.size(), 1);
    }
}

} // namespace dds::remote_config
