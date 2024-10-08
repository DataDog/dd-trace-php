// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include "json_helper.hpp"
#include <engine.hpp>
#include <memory>
#include <rapidjson/document.h>
#include <subscriber/waf.hpp>

const std::string waf_rule =
    R"({"version":"2.1","rules":[{"id":"1","name":"rule1","tags":{"type":"flow1","category":"category1"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg1","key_path":[]}],"regex":"^string.*"}},{"operator":"match_regex","parameters":{"inputs":[{"address":"arg2","key_path":[]}],"regex":".*"}}]}]})";
const std::string waf_rule_with_data =
    R"({"version":"2.1","rules":[{"id":"blk-001-001","name":"Block IP Addresses","tags":{"type":"block_ip","category":"security_response"},"conditions":[{"parameters":{"inputs":[{"address":"http.client_ip"}],"data":"blocked_ips"},"operator":"ip_match"}],"transformers":[],"on_match":["block"]}]})";

namespace dds {

namespace mock {
class listener : public dds::subscriber::listener {
public:
    MOCK_METHOD2(call, void(dds::parameter_view &, dds::event &));
    MOCK_METHOD2(
        get_meta_and_metrics, void(std::map<std::string, std::string> &,
                                  std::map<std::string_view, double> &));
};

class subscriber : public dds::subscriber {
public:
    MOCK_METHOD0(get_name, std::string_view());
    MOCK_METHOD0(get_listener, std::unique_ptr<dds::subscriber::listener>());
    MOCK_METHOD0(get_subscriptions, std::unordered_set<std::string>());
    MOCK_METHOD3(update, std::unique_ptr<dds::subscriber>(dds::parameter &,
                             std::map<std::string, std::string> &meta,
                             std::map<std::string_view, double> &metrics));
};
} // namespace mock

TEST(EngineTest, NoSubscriptors)
{
    auto e{engine::create()};
    auto ctx = e->get_context();

    parameter p = parameter::map();
    p.add("a", parameter::string("value"sv));
    auto res = ctx.publish(std::move(p));
    EXPECT_FALSE(res);
}

TEST(EngineTest, SingleSubscriptor)
{
    auto e{engine::create()};

    auto sub = std::make_unique<mock::subscriber>();
    EXPECT_CALL(*sub, get_listener()).WillRepeatedly(Invoke([]() {
        auto listener = std::make_unique<mock::listener>();
        EXPECT_CALL(*listener, call(_, _))
            .WillRepeatedly(Invoke(
                [](dds::parameter_view &data, dds::event &event_) -> void {
                    event_.actions.push_back({dds::action_type::block, {}});
                }));
        return listener;
    }));

    e->subscribe(std::move(sub));

    auto ctx = e->get_context();

    parameter p = parameter::map();
    p.add("a", parameter::string("value"sv));
    auto res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::block);

    p = parameter::map();
    p.add("b", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::block);
}

using namespace std::literals;

TEST(EngineTest, MultipleSubscriptors)
{
    auto e{engine::create()};

    auto blocker = std::make_unique<mock::listener>();
    EXPECT_CALL(*blocker, call(_, _))
        .WillRepeatedly(
            Invoke([](dds::parameter_view &data, dds::event &event_) -> void {
                std::unordered_set<std::string_view> subs{"a", "b", "e", "f"};
                if (subs.find(data[0].parameterName) != subs.end()) {
                    event_.data.push_back("some event");
                    event_.actions.push_back({dds::action_type::block, {}});
                }
            }));

    auto recorder = std::make_unique<mock::listener>();
    EXPECT_CALL(*recorder, call(_, _))
        .WillRepeatedly(
            Invoke([](dds::parameter_view &data, dds::event &event_) -> void {
                std::unordered_set<std::string_view> subs{"c", "d", "e", "g"};
                if (subs.find(data[0].parameterName) != subs.end()) {
                    event_.data.push_back("some event");
                }
            }));

    std::unique_ptr<mock::listener> ignorer =
        std::unique_ptr<mock::listener>(new mock::listener());
    EXPECT_CALL(*ignorer, call(_, _)).Times(testing::AnyNumber());

    std::unique_ptr<mock::subscriber> sub1 =
        std::unique_ptr<mock::subscriber>(new mock::subscriber());
    EXPECT_CALL(*sub1, get_listener()).WillRepeatedly(Invoke([&]() {
        return std::move(blocker);
    }));

    std::unique_ptr<mock::subscriber> sub2 =
        std::unique_ptr<mock::subscriber>(new mock::subscriber());
    EXPECT_CALL(*sub2, get_listener()).WillRepeatedly(Invoke([&]() {
        return std::move(recorder);
    }));

    std::unique_ptr<mock::subscriber> sub3 =
        std::unique_ptr<mock::subscriber>(new mock::subscriber());
    EXPECT_CALL(*sub3, get_listener()).WillRepeatedly(Invoke([&]() {
        return std::move(ignorer);
    }));

    e->subscribe(std::move(sub1));
    e->subscribe(std::move(sub2));
    e->subscribe(std::move(sub3));

    auto ctx = e->get_context();

    parameter p = parameter::map();
    p.add("a", parameter::string("value"sv));
    auto res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::block);

    p = parameter::map();
    p.add("b", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::block);

    p = parameter::map();
    p.add("c", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::record);

    p = parameter::map();
    p.add("d", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::record);

    p = parameter::map();
    p.add("e", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::block);

    p = parameter::map();
    p.add("f", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::block);

    p = parameter::map();
    p.add("g", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::record);

    p = parameter::map();
    p.add("h", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_FALSE(res);

    p = parameter::map();
    p.add("a", parameter::string("value"sv));
    p.add("c", parameter::string("value"sv));
    p.add("h", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::block);

    p = parameter::map();
    p.add("c", parameter::string("value"sv));
    p.add("h", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::record);
}

TEST(EngineTest, StatefulSubscriptor)
{
    auto e{engine::create()};

    int attempt = 0;

    auto sub = std::make_unique<mock::subscriber>();
    EXPECT_CALL(*sub, get_listener()).WillRepeatedly(Invoke([&]() {
        auto listener = std::make_unique<mock::listener>();
        EXPECT_CALL(*listener, call(_, _))
            .Times(3)
            .WillRepeatedly(Invoke([&attempt](dds::parameter_view &data,
                                       dds::event &event_) -> void {
                if (attempt == 2 || attempt == 5) {
                    event_.actions.push_back({dds::action_type::block, {}});
                }
                attempt++;
            }));
        return listener;
    }));

    e->subscribe(std::move(sub));

    auto ctx = e->get_context();

    parameter p = parameter::map();
    p.add("sub1", parameter::string("value"sv));
    auto res = ctx.publish(std::move(p));
    EXPECT_FALSE(res);

    p = parameter::map();
    p.add("sub2", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_FALSE(res);

    p = parameter::map();
    p.add("final", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::block);

    auto ctx2 = e->get_context();

    p = parameter::map();
    p.add("final", parameter::string("value"sv));
    res = ctx2.publish(std::move(p));
    EXPECT_FALSE(res);

    p = parameter::map();
    p.add("sub1", parameter::string("value"sv));
    res = ctx2.publish(std::move(p));
    EXPECT_FALSE(res);

    p = parameter::map();
    p.add("sub2", parameter::string("value"sv));
    res = ctx2.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::block);
}

TEST(EngineTest, WafDefaultActions)
{
    auto e{engine::create(engine_settings::default_trace_rate_limit)};

    auto listener = std::make_unique<mock::listener>();
    EXPECT_CALL(*listener, call(_, _))
        .WillRepeatedly(Invoke([](dds::parameter_view &data,
                                   dds::event &event_) -> void {
            event_.actions.push_back({dds::action_type::redirect, {}});
            event_.actions.push_back({dds::action_type::block, {}});
            event_.actions.push_back({dds::action_type::stack_trace, {}});
            event_.actions.push_back({dds::action_type::extract_schema, {}});
        }));

    auto sub = std::make_unique<mock::subscriber>();
    EXPECT_CALL(*sub, get_listener()).WillOnce(Invoke([&]() {
        return std::move(listener);
    }));

    e->subscribe(std::move(sub));

    auto ctx = e->get_context();

    parameter p = parameter::map();
    p.add("a", parameter::string("value"sv));
    auto res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(4, res->actions.size());
    EXPECT_EQ(res->actions[0].type, dds::action_type::redirect);
    EXPECT_EQ(res->actions[1].type, dds::action_type::block);
    EXPECT_EQ(res->actions[2].type, dds::action_type::stack_trace);
    EXPECT_EQ(res->actions[3].type, dds::action_type::extract_schema);

    p = parameter::map();
    p.add("b", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(4, res->actions.size());
    EXPECT_EQ(res->actions[0].type, dds::action_type::redirect);
    EXPECT_EQ(res->actions[1].type, dds::action_type::block);
    EXPECT_EQ(res->actions[2].type, dds::action_type::stack_trace);
    EXPECT_EQ(res->actions[3].type, dds::action_type::extract_schema);
}

TEST(EngineTest, InvalidActionsAreDiscarded)
{
    auto e{engine::create(engine_settings::default_trace_rate_limit)};

    auto listener = std::make_unique<mock::listener>();
    EXPECT_CALL(*listener, call(_, _))
        .WillRepeatedly(
            Invoke([](dds::parameter_view &data, dds::event &event_) -> void {
                event_.actions.push_back({dds::action_type::invalid, {}});
                event_.actions.push_back({dds::action_type::block, {}});
            }));

    auto sub = std::make_unique<mock::subscriber>();
    EXPECT_CALL(*sub, get_listener()).WillOnce(Invoke([&]() {
        return std::move(listener);
    }));

    e->subscribe(std::move(sub));

    auto ctx = e->get_context();

    parameter p = parameter::map();
    p.add("a", parameter::string("value"sv));
    auto res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(1, res->actions.size());
    EXPECT_EQ(res->actions[0].type, dds::action_type::block);

    p = parameter::map();
    p.add("b", parameter::string("value"sv));
    res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(1, res->actions.size());
    EXPECT_EQ(res->actions[0].type, dds::action_type::block);
}

TEST(EngineTest, WafSubscriptorBasic)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    auto e{engine::create()};

    auto waf_uniq_ptr = waf::instance::from_string(waf_rule, meta, metrics);
    auto *waf_ptr = waf_uniq_ptr.get();

    e->subscribe(std::move(waf_uniq_ptr));

    EXPECT_STREQ(waf_ptr->get_name().data(), "waf");

    auto ctx = e->get_context();

    auto p = parameter::map();
    p.add("arg1", parameter::string("string 1"sv));
    p.add("arg2", parameter::string("string 3"sv));

    auto res = ctx.publish(std::move(p));
    EXPECT_TRUE(res);
    EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    EXPECT_EQ(res->events.size(), 1);
    for (auto &match : res->events) {
        rapidjson::Document doc;
        doc.Parse(match);
        EXPECT_FALSE(doc.HasParseError());
        EXPECT_TRUE(doc.IsObject());
    }
}

TEST(EngineTest, WafSubscriptorInvalidParam)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule, meta, metrics));

    auto ctx = e->get_context();

    auto p = parameter::array();

    EXPECT_THROW(ctx.publish(std::move(p)), invalid_object);
}

TEST(EngineTest, WafSubscriptorTimeout)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule, meta, metrics, 0));

    auto ctx = e->get_context();

    auto p = parameter::map();
    p.add("arg1", parameter::string("string 1"sv));
    p.add("arg2", parameter::string("string 3"sv));

    auto res = ctx.publish(std::move(p));
    EXPECT_FALSE(res);
}

TEST(EngineTest, MockSubscriptorsUpdateRuleData)
{
    auto e{engine::create()};

    auto ignorer = []() {
        auto listener = std::make_unique<mock::listener>();
        EXPECT_CALL(*listener, call(_, _)).Times(testing::AnyNumber());
        return listener;
    };

    auto new_sub1 = std::make_unique<mock::subscriber>();
    EXPECT_CALL(*new_sub1, get_listener()).WillOnce(Invoke([&]() {
        return ignorer();
    }));

    auto sub1 = std::make_unique<mock::subscriber>();
    EXPECT_CALL(*sub1, update(_, _, _)).WillOnce(Invoke([&]() {
        return std::move(new_sub1);
    }));
    EXPECT_CALL(*sub1, get_name()).WillRepeatedly(Return(""));

    auto new_sub2 = std::make_unique<mock::subscriber>();
    EXPECT_CALL(*new_sub2, get_listener()).WillOnce(Invoke([&]() {
        return ignorer();
    }));

    auto sub2 = std::make_unique<mock::subscriber>();
    EXPECT_CALL(*sub2, update(_, _, _)).WillOnce(Invoke([&]() {
        return std::move(new_sub2);
    }));
    EXPECT_CALL(*sub2, get_name()).WillRepeatedly(Return(""));

    e->subscribe(std::move(sub1));
    e->subscribe(std::move(sub2));

    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    engine_ruleset ruleset(
        R"({"rules_data":[{"id":"blocked_ips","type":"data_with_expiration","data":[{"value":"192.168.1.1","expiration":"9999999999"}]}]})");
    e->update(ruleset, meta, metrics);

    // Ensure after the update we still have the same number of subscribers
    auto ctx = e->get_context();

    auto p = parameter::map();
    p.add("http.client_ip", parameter::string("192.168.1.1"sv));

    auto res = ctx.publish(std::move(p));
    EXPECT_FALSE(res);
}

TEST(EngineTest, MockSubscriptorsInvalidRuleData)
{
    auto e{engine::create()};

    auto ignorer = []() {
        auto listener = std::make_unique<mock::listener>();
        EXPECT_CALL(*listener, call(_, _)).Times(testing::AnyNumber());
        return listener;
    };

    auto sub1 = std::make_unique<mock::subscriber>();
    EXPECT_CALL(*sub1, update(_, _, _)).WillRepeatedly(Throw(std::exception()));
    EXPECT_CALL(*sub1, get_name()).WillRepeatedly(Return(""));
    EXPECT_CALL(*sub1, get_listener()).WillOnce(Invoke([&]() {
        return ignorer();
    }));

    auto sub2 = std::make_unique<mock::subscriber>();
    EXPECT_CALL(*sub2, update(_, _, _)).WillRepeatedly(Throw(std::exception()));
    EXPECT_CALL(*sub2, get_name()).WillRepeatedly(Return(""));
    EXPECT_CALL(*sub2, get_listener()).WillOnce(Invoke([&]() {
        return ignorer();
    }));

    e->subscribe(std::move(sub1));
    e->subscribe(std::move(sub2));

    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    engine_ruleset ruleset(R"({})");
    // All subscribers should be called regardless of failures
    e->update(ruleset, meta, metrics);

    // Ensure after the update we still have the same number of subscribers
    auto ctx = e->get_context();

    auto p = parameter::map();
    p.add("http.client_ip", parameter::string("192.168.1.1"sv));

    auto res = ctx.publish(std::move(p));
    EXPECT_FALSE(res);
}

TEST(EngineTest, WafSubscriptorUpdateRuleData)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule_with_data, meta, metrics));

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("192.168.1.1"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    {

        engine_ruleset rule_data(
            R"({"rules_data":[{"id":"blocked_ips","type":"data_with_expiration","data":[{"value":"192.168.1.1","expiration":"9999999999"}]}]})");
        e->update(rule_data, meta, metrics);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("192.168.1.1"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::block);
        EXPECT_EQ(res->events.size(), 1);
    }

    {
        engine_ruleset rule_data(
            R"({"rules_data":[{"id":"blocked_ips","type":"data_with_expiration","data":[{"value":"192.168.1.2","expiration":"9999999999"}]}]})");
        e->update(rule_data, meta, metrics);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("192.168.1.1"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }
}

TEST(EngineTest, WafSubscriptorInvalidRuleData)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule_with_data, meta, metrics));

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("192.168.1.1"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    {
        engine_ruleset rule_data(
            R"({"id":"blocked_ips","type":"data_with_expiration","data":[{"value":"192.168.1.1","expiration":"9999999999"}]})");
        e->update(rule_data, meta, metrics);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("192.168.1.1"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }
}

TEST(EngineTest, WafSubscriptorUpdateRules)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule_with_data, meta, metrics));

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("server.request.query", parameter::string("/some-url"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    {
        engine_ruleset update(
            R"({"version": "2.2", "rules": [{"id": "some id", "name": "some name", "tags": {"type": "lfi", "category": "attack_attempt"}, "conditions": [{"parameters": {"inputs": [{"address": "server.request.query"} ], "list": ["/some-url"] }, "operator": "phrase_match"} ], "on_match": ["block"] } ] })");
        e->update(update, meta, metrics);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("server.request.query", parameter::string("/some-url"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::block);
        EXPECT_EQ(res->events.size(), 1);
    }
}

TEST(EngineTest, WafSubscriptorUpdateRuleOverride)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule, meta, metrics));

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 3"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
    }

    {
        engine_ruleset update(
            R"({"rules_override": [{"rules_target":[{"rule_id":"1"}],
             "enabled": "false"}]})");
        e->update(update, meta, metrics);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 3"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    {
        engine_ruleset update(R"({"rules_override": []})");
        e->update(update, meta, metrics);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 3"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
    }
}

TEST(EngineTest, WafSubscriptorUpdateRuleOverrideAndActions)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule, meta, metrics));

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 3"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }

    {
        engine_ruleset update(
            R"({"rules_override": [{"rules_target":[{"rule_id":"1"}],
             "on_match": ["redirect"]}], "actions": [{"id": "redirect",
             "type": "redirect_request", "parameters": {"status_code": "303",
             "location": "localhost"}}]})");
        e->update(update, meta, metrics);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 3"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::redirect);
    }

    {
        engine_ruleset update(
            R"({"rules_override": [{"rules_target":[{"rule_id":"1"}],
             "on_match": ["redirect"]}], "actions": []})");
        e->update(update, meta, metrics);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 3"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }
}

TEST(EngineTest, WafSubscriptorExclusions)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule, meta, metrics));

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 3"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }

    {
        engine_ruleset update(
            R"({"exclusions": [{"id": "1",
             "rules_target":[{"rule_id":"1"}]}]})");
        e->update(update, meta, metrics);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 3"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    {
        engine_ruleset update(R"({"exclusions": []})");
        e->update(update, meta, metrics);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 3"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
    }
}

TEST(EngineTest, WafSubscriptorCustomRules)
{
    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;

    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule, meta, metrics));

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg3", parameter::string("custom rule"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    {
        // Make sure base rules still work
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 3"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }
    {
        engine_ruleset update(
            R"({"custom_rules":[{"id":"1","name":"custom_rule1","tags":{"type":"custom","category":"custom"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg3","key_path":[]}],"regex":"^custom.*"}}],"on_match":["block"]}]})");
        e->update(update, meta, metrics);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg3", parameter::string("custom rule"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::block);
    }

    {
        // Make sure base rules still work
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 3"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }

    {
        engine_ruleset update(R"({"custom_rules": []})");
        e->update(update, meta, metrics);
    }

    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg3", parameter::string("custom rule"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    {
        // Make sure base rules still work
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 3"sv));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->actions[0].type, dds::action_type::record);
    }
}

TEST(EngineTest, RateLimiterForceKeep)
{
    // Rate limit 0 allows all calls
    int rate_limit = 0;
    auto e{engine::create(rate_limit)};

    auto listener = std::make_unique<mock::listener>();
    EXPECT_CALL(*listener, call(_, _))
        .WillRepeatedly(
            Invoke([](dds::parameter_view &data, dds::event &event_) -> void {
                event_.actions.push_back({dds::action_type::redirect, {}});
            }));

    auto sub = std::make_unique<mock::subscriber>();
    EXPECT_CALL(*sub, get_listener()).WillOnce(Invoke([&]() {
        return std::move(listener);
    }));

    e->subscribe(std::move(sub));

    parameter p = parameter::map();
    p.add("a", parameter::string("value"sv));
    auto res = e->get_context().publish(std::move(p));
    EXPECT_TRUE(res->force_keep);
}

TEST(EngineTest, RateLimiterDoNotForceKeep)
{
    // Lets set max 1 per second and do two calls
    int rate_limit = 1;
    auto e{engine::create(rate_limit)};

    auto sub = std::make_unique<mock::subscriber>();
    EXPECT_CALL(*sub, get_listener()).WillRepeatedly(Invoke([&]() {
        auto listener = std::make_unique<mock::listener>();
        EXPECT_CALL(*listener, call(_, _))
            .WillOnce(Invoke(
                [](dds::parameter_view &data, dds::event &event_) -> void {
                    event_.actions.push_back({dds::action_type::redirect, {}});
                }));
        return listener;
    }));

    e->subscribe(std::move(sub));

    parameter p = parameter::map();
    p.add("a", parameter::string("value"sv));
    auto res = e->get_context().publish(std::move(p));
    parameter p2 = parameter::map();
    p2.add("a", parameter::string("value"sv));
    res = e->get_context().publish(std::move(p2));
    EXPECT_FALSE(res->force_keep);
}

} // namespace dds
