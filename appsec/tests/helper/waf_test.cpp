// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "common.hpp"
#include "engine_settings.hpp"
#include "json_helper.hpp"
#include "metrics.hpp"
#include "tel_subm_mock.hpp"
#include <rapidjson/document.h>
#include <spdlog/details/null_mutex.h>
#include <spdlog/sinks/base_sink.h>
#include <subscriber/waf.hpp>
#include <utils.hpp>

const std::string waf_rule =
    R"({"version": "2.1", "metadata": {"rules_version": "1.2.3"}, "rules": [{"id": "1", "name": "rule1", "tags": {"type": "flow1", "category": "category1"}, "conditions": [{"operator": "match_regex", "parameters": {"inputs": [{"address": "arg1", "key_path": [] } ], "regex": "^string.*"} }, {"operator": "match_regex", "parameters": {"inputs": [{"address": "arg2", "key_path": [] } ], "regex": ".*"} } ], "action": "record"} ], "processors": [{"id": "processor-001", "generator": "extract_schema", "parameters": {"mappings": [{"inputs": [{"address": "arg2"} ], "output": "_dd.appsec.s.arg2"} ], "scanners": [{"tags": {"category": "pii"} } ] }, "evaluate": false, "output": true } ], "scanners": [] })";
const std::string waf_rule_with_data =
    R"({"version":"2.1","rules":[{"id":"blk-001-001","name":"Block IP Addresses","tags":{"type":"block_ip","category":"security_response"},"conditions":[{"parameters":{"inputs":[{"address":"http.client_ip"}],"data":"blocked_ips"},"operator":"ip_match"}],"transformers":[],"on_match":["block"]}]})";

namespace dds {

template <typename Mutex>
class log_counter_sink : public spdlog::sinks::base_sink<Mutex> {
public:
    size_t count() const noexcept { return counter; }
    void clear() noexcept { counter = 0; }

protected:
    void sink_it_(const spdlog::details::log_msg &msg) override { counter++; }

    void flush_() override {}

    size_t counter{0};
};

using log_counter_sink_st = log_counter_sink<spdlog::details::null_mutex>;

TEST(WafTest, InitWithInvalidRules)
{
    engine_settings cs;
    cs.rules_file = create_sample_rules_invalid();
    auto ruleset = engine_ruleset::from_path(cs.rules_file);
    mock::tel_submitter submitm{};

    EXPECT_CALL(submitm,
        submit_legacy_meta(metrics::waf_version, std::string{"1.16.0"}));
    std::string rules_errors;
    EXPECT_CALL(submitm, submit_legacy_meta(metrics::event_rules_errors, _))
        .WillOnce(SaveArg<1>(&rules_errors));

    EXPECT_CALL(
        submitm, submit_legacy_metric(metrics::event_rules_loaded, 1.0));
    EXPECT_CALL(
        submitm, submit_legacy_metric(metrics::event_rules_failed, 4.0));

    subscriber::ptr wi{waf::instance::from_settings(cs, ruleset, submitm)};

    Mock::VerifyAndClearExpectations(&submitm);

    rapidjson::Document doc;
    doc.Parse(rules_errors);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());
    EXPECT_TRUE(doc.HasMember("missing key 'type'"));
    EXPECT_TRUE(doc.HasMember("unknown matcher: squash"));
    EXPECT_TRUE(doc.HasMember("missing key 'inputs'"));
}

TEST(WafTest, RunWithInvalidParam)
{
    NiceMock<mock::tel_submitter> submitm{};
    subscriber::ptr wi{waf::instance::from_string(waf_rule, submitm)};
    auto ctx = wi->get_listener();
    parameter_view pv;
    EXPECT_THROW(ctx->call(pv), invalid_object);
}

TEST(WafTest, RunWithTimeout)
{
    NiceMock<mock::tel_submitter> submitm{};

    subscriber::ptr wi(waf::instance::from_string(waf_rule, submitm, 0));
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter::string("string 1"sv));
    p.add("arg2", parameter::string("string 2"sv));

    parameter_view pv(p);
    EXPECT_THROW(ctx->call(pv), timeout_error);
}

TEST(WafTest, ValidRunGood)
{
    NiceMock<mock::tel_submitter> submitm{};
    subscriber::ptr wi{waf::instance::from_string(waf_rule, submitm)};
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter::string("string 1"sv));

    parameter_view pv(p);
    auto res = ctx->call(pv);
    EXPECT_TRUE(!res);

    EXPECT_CALL(submitm,
        submit_legacy_meta(metrics::event_rules_version, std::string{"1.2.3"}));
    double duration;
    EXPECT_CALL(submitm, submit_legacy_metric(metrics::waf_duration, _))
        .WillOnce(SaveArg<1>(&duration));
    EXPECT_CALL(submitm, submit_metric("waf.requests"sv, 1,
                             "event_rules_version:1.2.3,waf_version:1.16.0"));
    ctx->submit_metrics(submitm);
    EXPECT_GT(duration, 0.0);
    Mock::VerifyAndClearExpectations(&submitm);
}

TEST(WafTest, ValidRunMonitor)
{
    NiceMock<mock::tel_submitter> submitm{};
    subscriber::ptr wi{waf::instance::from_string(waf_rule, submitm)};
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter::string("string 1"sv));
    p.add("arg2", parameter::string("string 3"sv));

    parameter_view pv(p);
    auto res = ctx->call(pv);
    EXPECT_TRUE(res);

    for (auto &match : res->data) {
        rapidjson::Document doc;
        doc.Parse(match);
        EXPECT_FALSE(doc.HasParseError());
        EXPECT_TRUE(doc.IsObject());
    }

    EXPECT_TRUE(res->actions.empty());

    EXPECT_CALL(submitm,
        submit_legacy_meta(metrics::event_rules_version, std::string{"1.2.3"}));
    EXPECT_CALL(submitm, submit_legacy_metric(metrics::waf_duration, _));
    EXPECT_CALL(submitm, submit_metric("waf.requests"sv, 1,
                             "event_rules_version:1.2.3,waf_version:1.16.0,"
                             "rule_triggered:true"));
    EXPECT_CALL(
        submitm, submit_legacy_meta_copy_key(
                     std::string{"_dd.appsec.s.arg2"}, std::string{"[8]"}));
    ctx->submit_metrics(submitm);
    Mock::VerifyAndClearExpectations(&submitm);
}

TEST(WafTest, ValidRunMonitorObfuscated)
{
    NiceMock<mock::tel_submitter> submitm{};

    subscriber::ptr wi{waf::instance::from_string(waf_rule, submitm,
        waf::instance::default_waf_timeout_us, "password"sv, "string 3"sv)};
    auto ctx = wi->get_listener();

    auto p = parameter::map(), sub_p = parameter::map();
    sub_p.add("password", parameter::string("string 1"sv));
    p.add("arg1", std::move(sub_p));
    p.add("arg2", parameter::string("string 3"sv));

    parameter_view pv(p);
    auto res = ctx->call(pv);
    EXPECT_TRUE(res);

    EXPECT_EQ(res->data.size(), 1);
    rapidjson::Document doc;
    doc.Parse(res->data[0]);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());

    EXPECT_STREQ(doc["rule_matches"][0]["parameters"][0]["value"].GetString(),
        "<Redacted>");
    EXPECT_STREQ(doc["rule_matches"][1]["parameters"][0]["value"].GetString(),
        "<Redacted>");

    EXPECT_TRUE(res->actions.empty());
}

TEST(WafTest, ValidRunMonitorObfuscatedFromSettings)
{
    NiceMock<mock::tel_submitter> submitm{};

    engine_settings cs;
    cs.rules_file = create_sample_rules_ok();
    cs.obfuscator_key_regex = "password";
    auto ruleset = engine_ruleset::from_path(cs.rules_file);

    subscriber::ptr wi{waf::instance::from_settings(cs, ruleset, submitm)};

    auto ctx = wi->get_listener();

    auto p = parameter::map(), sub_p = parameter::map();
    sub_p.add("password", parameter::string("acunetix-product"sv));
    p.add("server.request.headers.no_cookies", std::move(sub_p));

    parameter_view pv(p);
    auto res = ctx->call(pv);
    EXPECT_TRUE(res);

    EXPECT_EQ(res->data.size(), 1);
    rapidjson::Document doc;
    doc.Parse(res->data[0]);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());

    EXPECT_TRUE(res->actions.empty());

    EXPECT_STREQ(doc["rule_matches"][0]["parameters"][0]["value"].GetString(),
        "<Redacted>");
}

TEST(WafTest, UpdateRuleData)
{
    NiceMock<mock::tel_submitter> submitm{};

    subscriber::ptr wi{waf::instance::from_string(waf_rule_with_data, submitm)};
    ASSERT_TRUE(wi);

    auto addresses = wi->get_subscriptions();
    EXPECT_EQ(addresses.size(), 1);
    EXPECT_STREQ(addresses.begin()->c_str(), "http.client_ip");

    {
        auto ctx = wi->get_listener();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("192.168.1.1"sv));

        parameter_view pv(p);
        auto res = ctx->call(pv);
        EXPECT_TRUE(!res);
    }

    auto param = json_to_parameter(
        R"({"rules_data":[{"id":"blocked_ips","type":"data_with_expiration","data":[{"value":"192.168.1.1","expiration":"9999999999"}]}]})");

    wi = wi->update(param, submitm);
    ASSERT_TRUE(wi);

    addresses = wi->get_subscriptions();
    EXPECT_EQ(addresses.size(), 1);
    EXPECT_STREQ(addresses.begin()->c_str(), "http.client_ip");

    {
        auto ctx = wi->get_listener();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("192.168.1.1"sv));

        parameter_view pv(p);
        auto res = ctx->call(pv);
        EXPECT_TRUE(res);

        EXPECT_EQ(res->data.size(), 1);
        rapidjson::Document doc;
        doc.Parse(res->data[0]);
        EXPECT_FALSE(doc.HasParseError());
        EXPECT_TRUE(doc.IsObject());

        EXPECT_STREQ(
            doc["rule_matches"][0]["parameters"][0]["value"].GetString(),
            "192.168.1.1");

        EXPECT_EQ(res->actions.size(), 1);
        EXPECT_STREQ(res->actions.begin()->c_str(), "block");
    }

    Mock::VerifyAndClearExpectations(&submitm);
}

TEST(WafTest, UpdateInvalid)
{
    NiceMock<mock::tel_submitter> submitm{};
    subscriber::ptr wi{waf::instance::from_string(waf_rule_with_data, submitm)};
    ASSERT_TRUE(wi);

    {
        auto ctx = wi->get_listener();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("192.168.1.1"sv));

        parameter_view pv(p);
        auto res = ctx->call(pv);
        EXPECT_TRUE(!res);
    }

    auto param = json_to_parameter(R"({})");

    EXPECT_CALL(
        submitm, submit_metric("waf.updates"sv, 1,
                     "success:false,event_rules_version:,waf_version:1.16.0"));
    ASSERT_THROW(wi->update(param, submitm), invalid_object);
}

TEST(WafTest, SchemasAreAdded)
{
    NiceMock<mock::tel_submitter> submitm{};

    subscriber::ptr wi{waf::instance::from_string(waf_rule, submitm)};
    auto ctx = wi->get_listener();

    auto p = parameter::map(), sub_p = parameter::map();
    sub_p.add("password", parameter::string("string 1"sv));
    p.add("arg1", std::move(sub_p));
    p.add("arg2", parameter::string("string 3"sv));

    parameter_view pv(p);
    auto res = ctx->call(pv);
    EXPECT_TRUE(res);

    EXPECT_EQ(res->data.size(), 1);
    rapidjson::Document doc;
    doc.Parse(res->data[0]);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());

    EXPECT_CALL(submitm, submit_metric("waf.requests"sv, 1,
                             "event_rules_version:1.2.3,waf_version:1.16.0,"
                             "rule_triggered:true"));
    EXPECT_CALL(
        submitm, submit_legacy_meta("_dd.appsec.event_rules.version", "1.2.3"));
    EXPECT_CALL(submitm, submit_legacy_metric("_dd.appsec.waf.duration"sv, _));
    EXPECT_CALL(
        submitm, submit_legacy_meta_copy_key(
                     std::string{"_dd.appsec.s.arg2"}, std::string{"[8]"}));
    ctx->submit_metrics(submitm);
    Mock::VerifyAndClearExpectations(&submitm);
}
} // namespace dds
