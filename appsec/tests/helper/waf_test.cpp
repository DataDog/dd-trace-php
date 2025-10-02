// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "common.hpp"
#include "ddwaf.h"
#include "engine_settings.hpp"
#include "json_helper.hpp"
#include "metrics.hpp"
#include "remote_config/config.hpp"
#include "tel_subm_mock.hpp"
#include <gtest/gtest.h>
#include <rapidjson/document.h>
#include <spdlog/details/null_mutex.h>
#include <spdlog/sinks/base_sink.h>
#include <subscriber/waf.hpp>
#include <utils.hpp>

using dds::remote_config::changeset;
using dds::remote_config::parsed_config_key;

const std::string waf_rule =
    R"({"version": "2.1", "metadata": {"rules_version": "1.2.3" }, "rules": [{"id": "1", "name": "rule1", "tags": {"type": "flow1", "category": "category1" }, "conditions": [{"operator": "match_regex", "parameters": {"inputs": [{"address": "arg1", "key_path": [] } ], "regex": "^string.*" } }, {"operator": "match_regex", "parameters": {"inputs": [{"address": "arg2", "key_path": [] } ], "regex": ".*" } } ], "action": "record" }, {"id": "2", "name": "ssrf", "tags": {"type": "ssrf", "category": "vulnerability_trigger" }, "conditions": [{"parameters": {"resource": [{"address": "server.io.net.url" } ], "params": [{"address": "server.request.body" } ], "options": {"enforce-policy-without-injection": true} }, "operator": "ssrf_detector@v3" } ] }, {"id": "3", "name": "lfi", "tags": {"type": "lfi", "category": "vulnerability_trigger" }, "conditions": [{"parameters": {"params": [{"address": "server.request.query" } ], "resource": [{"address": "server.io.fs.file" } ] }, "operator": "lfi_detector" } ] } ], "rules_compat": [{"id": "ttr-000-001", "name": "Trace Tagging Rule: Attributes, No Keep, No Event", "tags": {"type": "security_scanner", "category": "attack_attempt" }, "conditions": [{"operator": "match_regex", "parameters": {"inputs": [{"address": "arg3", "key_path": [] } ], "regex": "^string.*" } } ], "output": {"event": false, "keep": false, "attributes": {"_dd.appsec.trace.integer": {"value": 12345 }, "_dd.appsec.trace.float": {"value": 12.34 }, "_dd.appsec.trace.string": {"value": "678" }, "_dd.appsec.trace.agent": {"address": "server.request.headers.no_cookies", "key_path": ["user-agent" ] } } }, "on_match": [] } ], "processors": [{"id": "processor-001", "generator": "extract_schema", "parameters": {"mappings": [{"inputs": [{"address": "arg2" } ], "output": "_dd.appsec.s.arg2" } ], "scanners": [{"tags": {"category": "pii" } } ] }, "evaluate": false, "output": true } ], "scanners": [] })";
const std::string waf_rule_with_data =
    R"({"version":"2.1","rules":[{"id":"blk-001-001","name":"Block IP Addresses","tags":{"type":"block_ip","category":"security_response"},"conditions":[{"parameters":{"inputs":[{"address":"http.client_ip"}],"data":"blocked_ips"},"operator":"ip_match"}],"transformers":[],"on_match":["block"]}]})";

namespace {
dds::parameter param_from_file(std::string_view sv)
{
    rapidjson::Document doc;
    std::string file_content{dds::read_file(sv)};
    rapidjson::ParseResult const result = doc.Parse(file_content);
    if ((result == nullptr) || !doc.IsObject()) {
        throw dds::parsing_error("invalid json rule");
    }
    return dds::json_to_parameter(doc);
}
} // namespace

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
    auto ruleset = param_from_file(cs.rules_file);
    mock::tel_submitter submitm{};

    EXPECT_CALL(submitm, submit_span_meta(metrics::waf_version,
                             std::string{ddwaf_get_version()}));
    std::string rules_errors;
    EXPECT_CALL(submitm, submit_span_meta(metrics::event_rules_errors, _))
        .WillOnce(SaveArg<1>(&rules_errors));

    EXPECT_CALL(submitm, submit_span_metric(metrics::event_rules_loaded, 1.0));
    EXPECT_CALL(submitm, submit_span_metric(metrics::event_rules_failed, 4.0));

    EXPECT_CALL(submitm, submit_metric("waf.init"sv, 1, _));
    EXPECT_CALL(
        submitm, submit_metric("waf.config_errors", 3.,
                     telemetry::telemetry_tags::from_string(
                         std::string{"waf_version:"} + ddwaf_get_version() +
                         ",event_rules_version:1.2.3,"
                         "config_key:rules,scope:item")));

    // diagnostics
    // rules:
    //   loaded:
    //     - "5"
    //   failed:
    //     - "1"
    //     - "2"
    //     - "3"
    //     - "4"
    //   skipped: []
    //   errors:
    //     missing key 'type':
    //       - "1"
    //       - "3"
    //     missing key 'inputs':
    //       - "4"
    //   warnings:
    //     unknown operator: 'squash':
    //       - "2"
    // ruleset_version: "1.2.3"

    std::shared_ptr<subscriber> wi{
        waf::instance::from_settings(cs, std::move(ruleset), submitm)};

    Mock::VerifyAndClearExpectations(&submitm);

    rapidjson::Document doc;
    doc.Parse(rules_errors);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());
    EXPECT_TRUE(doc.HasMember("missing key 'type'"));
    // warning is not included in _dd.appsec.event_rules.errors
    EXPECT_FALSE(doc.HasMember("unknown matcher: squash"));
    EXPECT_TRUE(doc.HasMember("missing key 'inputs'"));
}

TEST(WafTest, RunWithInvalidParam)
{
    { // No rasp
        NiceMock<mock::tel_submitter> submitm{};
        std::shared_ptr<subscriber> wi{
            waf::instance::from_string(waf_rule, submitm)};
        auto ctx = wi->get_listener();
        parameter_view pv;
        dds::event e;
        EXPECT_THROW(ctx->call(pv, e), invalid_object);
    }
    { // Rasp
        NiceMock<mock::tel_submitter> submitm{};
        std::shared_ptr<subscriber> wi(
            waf::instance::from_string(waf_rule, submitm, 0));
        auto ctx = wi->get_listener();

        EXPECT_CALL(submitm,
            submit_metric("waf.requests"sv, 1,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",waf_error:true"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.rule.eval"sv, 1,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:lfi"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.rule.match"sv, 0,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:lfi"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.timeout"sv, 0,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:lfi"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.error"sv, 1,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:lfi"})));

        parameter_view pv;
        dds::event e;
        std::string rasp = "lfi";
        EXPECT_THROW(ctx->call(pv, e, rasp), invalid_object);
        ctx->submit_metrics(submitm);
        Mock::VerifyAndClearExpectations(&submitm);
    }
}

TEST(WafTest, RunWithTimeout)
{
    { // No rasp
        NiceMock<mock::tel_submitter> submitm{};
        std::shared_ptr<subscriber> wi(
            waf::instance::from_string(waf_rule, submitm, 0));
        auto ctx = wi->get_listener();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 2"sv));

        parameter_view pv(p);
        dds::event e;
        EXPECT_THROW(ctx->call(pv, e), timeout_error);
    }
    { // Rasp
        NiceMock<mock::tel_submitter> submitm{};
        std::shared_ptr<subscriber> wi(
            waf::instance::from_string(waf_rule, submitm, 0));
        auto ctx = wi->get_listener();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));
        p.add("arg2", parameter::string("string 2"sv));

        EXPECT_CALL(submitm, submit_span_metric(metrics::rasp_timeout, 1));
        EXPECT_CALL(submitm, submit_span_metric(metrics::rasp_rule_eval, 1.0));
        // Since v1.22.0 libddwaf will still attempt to run denylists, which
        // will cause the duration to be non-zero
        EXPECT_CALL(submitm, submit_span_metric(metrics::waf_duration, _));
        EXPECT_CALL(submitm, submit_span_metric(metrics::rasp_duration, _));
        parameter_view pv(p);
        dds::event e;
        std::string rasp = "lfi";
        EXPECT_THROW(ctx->call(pv, e, rasp), timeout_error);

        ctx->submit_metrics(submitm);
        Mock::VerifyAndClearExpectations(&submitm);
    }
}

TEST(WafTest, ValidRunGood)
{
    { // No rasp event
        NiceMock<mock::tel_submitter> submitm{};
        std::shared_ptr<subscriber> wi{
            waf::instance::from_string(waf_rule, submitm)};
        auto ctx = wi->get_listener();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));

        parameter_view pv(p);
        dds::event e;
        ctx->call(pv, e); // default to rasp=false

        EXPECT_CALL(submitm, submit_span_meta(metrics::event_rules_version,
                                 std::string{"1.2.3"}));
        double duration;
        EXPECT_CALL(submitm, submit_span_metric(metrics::waf_duration, _))
            .WillOnce(SaveArg<1>(&duration));
        EXPECT_CALL(submitm,
            submit_metric("waf.requests"sv, 1,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version())));
        ctx->submit_metrics(submitm);
        EXPECT_GT(duration, 0.0);
        Mock::VerifyAndClearExpectations(&submitm);
    }

    { // Rasp event
        NiceMock<mock::tel_submitter> submitm{};
        std::shared_ptr<subscriber> wi{
            waf::instance::from_string(waf_rule, submitm)};
        auto ctx = wi->get_listener();

        auto p = parameter::map();
        p.add("arg1", parameter::string("string 1"sv));

        parameter_view pv(p);
        dds::event e;
        std::string rasp = "lfi";
        ctx->call(pv, e, rasp);

        double rasp_duration;
        double duration;

        EXPECT_CALL(submitm, submit_span_meta(metrics::event_rules_version,
                                 std::string{"1.2.3"}));
        EXPECT_CALL(submitm,
            submit_metric("waf.requests"sv, 1,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version())));
        EXPECT_CALL(submitm,
            submit_metric("rasp.rule.eval"sv, 1,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:lfi"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.rule.match"sv, 0,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:lfi"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.timeout"sv, 0,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:lfi"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.error"sv, 0,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:lfi"})));
        EXPECT_CALL(submitm, submit_span_metric(metrics::rasp_rule_eval, 1.0));
        EXPECT_CALL(submitm, submit_span_metric(metrics::waf_duration, _))
            .WillOnce(SaveArg<1>(&duration));
        EXPECT_CALL(submitm, submit_span_metric(metrics::rasp_duration, _))
            .WillOnce(SaveArg<1>(&rasp_duration));
        ctx->submit_metrics(submitm);
        EXPECT_EQ(duration, 0.0);
        EXPECT_GT(rasp_duration, 0);
    }
}

TEST(WafTest, ValidRunMonitor)
{
    NiceMock<mock::tel_submitter> submitm{};
    std::shared_ptr<subscriber> wi{
        waf::instance::from_string(waf_rule, submitm)};
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter::string("string 1"sv));
    p.add("arg2", parameter::string("string 3"sv));

    parameter_view pv(p);
    dds::event e;
    ctx->call(pv, e);

    for (auto &match : e.triggers) {
        rapidjson::Document doc;
        doc.Parse(match);
        EXPECT_FALSE(doc.HasParseError());
        EXPECT_TRUE(doc.IsObject());
    }

    EXPECT_TRUE(e.actions.empty());

    EXPECT_CALL(submitm,
        submit_span_meta(metrics::event_rules_version, std::string{"1.2.3"}));
    EXPECT_CALL(submitm, submit_span_metric(metrics::waf_duration, _));
    EXPECT_CALL(
        submitm, submit_metric("waf.requests"sv, 1,
                     telemetry::telemetry_tags::from_string(
                         std::string{"event_rules_version:1.2.3,waf_version:"} +
                         ddwaf_get_version() + ",rule_triggered:true")));
    EXPECT_CALL(
        submitm, submit_span_meta_copy_key(
                     std::string{"_dd.appsec.s.arg2"}, std::string{"[8]"}));
    ctx->submit_metrics(submitm);
    Mock::VerifyAndClearExpectations(&submitm);
}

TEST(WafTest, ValidRunMonitorObfuscated)
{
    NiceMock<mock::tel_submitter> submitm{};

    std::shared_ptr<subscriber> wi{waf::instance::from_string(waf_rule, submitm,
        waf::instance::default_waf_timeout_us, "password"sv, "string 3"sv)};
    auto ctx = wi->get_listener();

    auto p = parameter::map(), sub_p = parameter::map();
    sub_p.add("password", parameter::string("string 1"sv));
    p.add("arg1", std::move(sub_p));
    p.add("arg2", parameter::string("string 3"sv));

    parameter_view pv(p);
    dds::event e;
    ctx->call(pv, e);

    EXPECT_EQ(e.triggers.size(), 1);
    rapidjson::Document doc;
    doc.Parse(e.triggers[0]);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());

    EXPECT_STREQ(doc["rule_matches"][0]["parameters"][0]["value"].GetString(),
        "<Redacted>");
    EXPECT_STREQ(doc["rule_matches"][1]["parameters"][0]["value"].GetString(),
        "<Redacted>");
}

TEST(WafTest, ValidRunMonitorObfuscatedFromSettings)
{
    NiceMock<mock::tel_submitter> submitm{};

    engine_settings cs;
    cs.rules_file = create_sample_rules_ok();
    cs.obfuscator_key_regex = "password";
    auto ruleset = param_from_file(cs.rules_file);

    std::shared_ptr<subscriber> wi{
        waf::instance::from_settings(cs, std::move(ruleset), submitm)};

    auto ctx = wi->get_listener();

    auto p = parameter::map(), sub_p = parameter::map();
    sub_p.add("password", parameter::string("acunetix-product"sv));
    p.add("server.request.headers.no_cookies", std::move(sub_p));

    parameter_view pv(p);
    dds::event e;
    ctx->call(pv, e);

    EXPECT_EQ(e.triggers.size(), 1);
    rapidjson::Document doc;
    doc.Parse(e.triggers[0]);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());

    EXPECT_TRUE(e.actions.empty());

    EXPECT_STREQ(doc["rule_matches"][0]["parameters"][0]["value"].GetString(),
        "<Redacted>");
}

TEST(WafTest, UpdateRuleData)
{
    NiceMock<mock::tel_submitter> submitm{};

    std::shared_ptr<subscriber> wi{
        waf::instance::from_string(waf_rule_with_data, submitm)};
    ASSERT_TRUE(wi);

    auto addresses = wi->get_subscriptions();
    EXPECT_EQ(addresses.size(), 1);
    EXPECT_STREQ(addresses.begin()->c_str(), "http.client_ip");

    {
        auto ctx = wi->get_listener();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("192.168.1.1"sv));

        parameter_view pv(p);
        dds::event e;
        ctx->call(pv, e);
    }

    auto param = json_to_parameter(
        R"({"rules_data":[{"id":"blocked_ips","type":"data_with_expiration","data":[{"value":"192.168.1.1","expiration":"9999999999"}]}]})");
    changeset cs;
    cs.added[parsed_config_key{"employee/ASM_DATA/0/blocked_ips"}] =
        std::move(param);
    wi = wi->update(cs, submitm);
    ASSERT_TRUE(wi);

    addresses = wi->get_subscriptions();
    EXPECT_EQ(addresses.size(), 1);
    EXPECT_STREQ(addresses.begin()->c_str(), "http.client_ip");

    {
        auto ctx = wi->get_listener();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("192.168.1.1"sv));

        parameter_view pv(p);
        dds::event e;
        ctx->call(pv, e);

        EXPECT_EQ(e.triggers.size(), 1);
        rapidjson::Document doc;
        doc.Parse(e.triggers[0]);
        EXPECT_FALSE(doc.HasParseError());
        EXPECT_TRUE(doc.IsObject());

        EXPECT_STREQ(
            doc["rule_matches"][0]["parameters"][0]["value"].GetString(),
            "192.168.1.1");

        EXPECT_EQ(e.actions.size(), 1);
        EXPECT_EQ(e.actions.begin()->type, dds::action_type::block);
    }

    Mock::VerifyAndClearExpectations(&submitm);
}

TEST(WafTest, UpdateInvalid)
{
    NiceMock<mock::tel_submitter> submitm{};
    std::shared_ptr<subscriber> wi{
        waf::instance::from_string(waf_rule_with_data, submitm)};
    ASSERT_TRUE(wi);

    {
        auto ctx = wi->get_listener();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string("192.168.1.1"sv));

        parameter_view pv(p);
        dds::event e;
        ctx->call(pv, e);
    }

    changeset cs;
    cs.added.emplace("employee/ASM_DD/0/empty"sv, parameter::map());
    EXPECT_CALL(submitm,
        submit_metric("waf.updates"sv, 1,
            telemetry::telemetry_tags::from_string(
                std::string{"success:true,event_rules_version:,waf_version:"} +
                ddwaf_get_version())));

    // the update with the empty ASM_DD fails, so the default (in this case
    // waf_rule_with_data) is reloaded
    wi->update(cs, submitm);
}

TEST(WafTest, SchemasAreAdded)
{
    NiceMock<mock::tel_submitter> submitm{};

    std::shared_ptr<subscriber> wi{
        waf::instance::from_string(waf_rule, submitm)};
    auto ctx = wi->get_listener();

    auto p = parameter::map(), sub_p = parameter::map();
    sub_p.add("password", parameter::string("string 1"sv));
    p.add("arg1", std::move(sub_p));
    p.add("arg2", parameter::string("string 3"sv));

    parameter_view pv(p);
    dds::event e;
    ctx->call(pv, e);

    EXPECT_EQ(e.triggers.size(), 1);
    rapidjson::Document doc;
    doc.Parse(e.triggers[0]);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());

    EXPECT_CALL(
        submitm, submit_metric("waf.requests"sv, 1,
                     telemetry::telemetry_tags::from_string(
                         std::string{"event_rules_version:1.2.3,waf_version:"} +
                         ddwaf_get_version() + ",rule_triggered:true")));
    EXPECT_CALL(
        submitm, submit_span_meta("_dd.appsec.event_rules.version", "1.2.3"));
    EXPECT_CALL(submitm, submit_span_metric("_dd.appsec.waf.duration"sv, _));
    EXPECT_CALL(
        submitm, submit_span_meta_copy_key(
                     std::string{"_dd.appsec.s.arg2"}, std::string{"[8]"}));
    ctx->submit_metrics(submitm);
    Mock::VerifyAndClearExpectations(&submitm);
}

TEST(WafTest, FingerprintAreNotAdded)
{
    NiceMock<mock::tel_submitter> submitm{};

    engine_settings settings;
    settings.rules_file = create_sample_rules_ok();
    auto ruleset = param_from_file(settings.rules_file);

    std::shared_ptr<subscriber> wi{
        waf::instance::from_settings(settings, std::move(ruleset), submitm)};
    auto ctx = wi->get_listener();

    auto p = parameter::map();

    parameter_view pv(p);
    dds::event e;
    ctx->call(pv, e);

    EXPECT_CALL(submitm,
        submit_span_meta_copy_key(MatchesRegex("_dd\\.appsec\\.fp\\..+"), _))
        .Times(0);
    ctx->submit_metrics(submitm);
    Mock::VerifyAndClearExpectations(&submitm);
}

TEST(WafTest, FingerprintAreAdded)
{
    NiceMock<mock::tel_submitter> submitm{};

    engine_settings settings;
    settings.rules_file = create_sample_rules_ok();
    auto ruleset = param_from_file(settings.rules_file);

    std::shared_ptr<subscriber> wi{
        waf::instance::from_settings(settings, std::move(ruleset), submitm)};
    auto ctx = wi->get_listener();

    auto p = parameter::map();

    // Endpoint Fingerprint inputs
    auto query = parameter::map();
    query.add("query", parameter::string("asdfds"sv));
    p.add("server.request.uri.raw", parameter::string("asdfds"sv));
    p.add("server.request.method", parameter::string("GET"sv));
    p.add("server.request.query", std::move(query));

    // Network and Headers Fingerprint inputs
    auto headers = parameter::map();
    headers.add("X-Forwarded-For", parameter::string("192.168.72.0"sv));
    headers.add("user-agent", parameter::string("acunetix-product"sv));
    p.add("server.request.headers.no_cookies", std::move(headers));

    // Session Fingerprint inputs
    p.add("server.request.cookies", parameter::string("asdfds"sv));
    p.add("usr.session_id", parameter::string("asdfds"sv));
    p.add("usr.id", parameter::string("asdfds"sv));

    parameter_view pv(p);
    dds::event e;
    ctx->call(pv, e);

    EXPECT_CALL(
        submitm, submit_span_meta_copy_key("_dd.appsec.fp.http.endpoint",
                     testing::MatchesRegex("http-get(-[A-Za-z0-9]*){3}")));
    EXPECT_CALL(submitm, submit_span_meta_copy_key("_dd.appsec.fp.http.network",
                             testing::MatchesRegex("net-[0-9]*-[a-zA-Z0-9]*")));
    EXPECT_CALL(
        submitm, submit_span_meta_copy_key("_dd.appsec.fp.http.header",
                     testing::MatchesRegex("hdr(-[0-9]*-[a-zA-Z0-9]*){2}")));
    EXPECT_CALL(submitm, submit_span_meta_copy_key("_dd.appsec.fp.session",
                             testing::MatchesRegex("ssn(-[a-zA-Z0-9]*){4}")));
    ctx->submit_metrics(submitm);
    Mock::VerifyAndClearExpectations(&submitm);
}

TEST(WafTest, ActionsAreSentAndParsed)
{
    NiceMock<mock::tel_submitter> submitm{};

    auto p = parameter::map();
    p.add("http.client_ip", parameter::string("192.168.1.1"sv));
    parameter_view pv(p);

    { // Standard actions types with custom parameters
        std::string rules_with_actions =
            R"({"version":"2.1","rules":[{"id":"blk-001-001","name":"BlockIPAddresses","tags":{"type":"block_ip","category":"security_response"},"conditions":[{"parameters":{"inputs":[{"address":"http.client_ip"}],"data":"blocked_ips"},"operator":"ip_match"}],"transformers":[],"on_match":["custom"]}],"actions":[{"id":"custom","type":"block_request","parameters":{"status_code":123,"grpc_status_code":321,"type":"json","custom_param":"foo"}}],"rules_data":[{"id":"blocked_ips","type":"data_with_expiration","data":[{"value":"192.168.1.1","expiration":"9999999999"}]}]})";

        std::shared_ptr<subscriber> wi{
            waf::instance::from_string(rules_with_actions, submitm)};
        ASSERT_TRUE(wi);

        auto addresses = wi->get_subscriptions();
        EXPECT_EQ(addresses.size(), 1);
        EXPECT_STREQ(addresses.begin()->c_str(), "http.client_ip");

        auto ctx = wi->get_listener();

        dds::event e;
        ctx->call(pv, e);

        EXPECT_EQ(e.triggers.size(), 1);
        rapidjson::Document doc;
        doc.Parse(e.triggers[0]);
        EXPECT_FALSE(doc.HasParseError());
        EXPECT_TRUE(doc.IsObject());

        EXPECT_STREQ(
            doc["rule_matches"][0]["parameters"][0]["value"].GetString(),
            "192.168.1.1");

        auto action = e.actions.begin();
        EXPECT_EQ(e.actions.size(), 1);
        EXPECT_EQ(action->type, dds::action_type::block);
        EXPECT_STREQ(
            action->parameters.find("status_code")->second.c_str(), "123");
        EXPECT_STREQ(
            action->parameters.find("grpc_status_code")->second.c_str(), "321");
        EXPECT_STREQ(action->parameters.find("type")->second.c_str(), "json");
        EXPECT_STREQ(
            action->parameters.find("custom_param")->second.c_str(), "foo");
    }

    { // Standard actions types with no parameters
        std::string rules_with_actions =
            R"({"version":"2.1","rules":[{"id":"blk-001-001","name":"BlockIPAddresses","tags":{"type":"block_ip","category":"security_response"},"conditions":[{"parameters":{"inputs":[{"address":"http.client_ip"}],"data":"blocked_ips"},"operator":"ip_match"}],"transformers":[],"on_match":["custom"]}],"actions":[{"id":"custom","type":"block_request","parameters":{}}],"rules_data":[{"id":"blocked_ips","type":"data_with_expiration","data":[{"value":"192.168.1.1","expiration":"9999999999"}]}]})";

        std::shared_ptr<subscriber> wi{
            waf::instance::from_string(rules_with_actions, submitm)};
        ASSERT_TRUE(wi);

        auto addresses = wi->get_subscriptions();
        EXPECT_EQ(addresses.size(), 1);
        EXPECT_STREQ(addresses.begin()->c_str(), "http.client_ip");

        auto ctx = wi->get_listener();

        dds::event e;
        ctx->call(pv, e);

        EXPECT_EQ(e.triggers.size(), 1);
        rapidjson::Document doc;
        doc.Parse(e.triggers[0]);
        EXPECT_FALSE(doc.HasParseError());
        EXPECT_TRUE(doc.IsObject());

        EXPECT_STREQ(
            doc["rule_matches"][0]["parameters"][0]["value"].GetString(),
            "192.168.1.1");

        auto action = e.actions.begin();
        EXPECT_EQ(e.actions.size(), 1);
        EXPECT_EQ(action->type, dds::action_type::block);
        EXPECT_STREQ(action->parameters.find("status_code")->second.c_str(),
            "403"); // Default value
        EXPECT_STREQ(
            action->parameters.find("grpc_status_code")->second.c_str(),
            "10"); // Default value
        EXPECT_STREQ(action->parameters.find("type")->second.c_str(),
            "auto"); // Default value
    }

    { // Custom action types
        std::string rules_with_actions =
            R"({"version":"2.1","rules":[{"id":"blk-001-001","name":"BlockIPAddresses","tags":{"type":"block_ip","category":"security_response"},"conditions":[{"parameters":{"inputs":[{"address":"http.client_ip"}],"data":"blocked_ips"},"operator":"ip_match"}],"transformers":[],"on_match":["custom"]}],"actions":[{"id":"custom","type":"custom_type","parameters":{"some":"parameter"}}],"rules_data":[{"id":"blocked_ips","type":"data_with_expiration","data":[{"value":"192.168.1.1","expiration":"9999999999"}]}]})";

        std::shared_ptr<subscriber> wi{
            waf::instance::from_string(rules_with_actions, submitm)};
        ASSERT_TRUE(wi);

        auto addresses = wi->get_subscriptions();
        EXPECT_EQ(addresses.size(), 1);
        EXPECT_STREQ(addresses.begin()->c_str(), "http.client_ip");

        auto ctx = wi->get_listener();

        dds::event e;
        ctx->call(pv, e);

        EXPECT_EQ(e.triggers.size(), 1);
        rapidjson::Document doc;
        doc.Parse(e.triggers[0]);
        EXPECT_FALSE(doc.HasParseError());
        EXPECT_TRUE(doc.IsObject());

        EXPECT_STREQ(
            doc["rule_matches"][0]["parameters"][0]["value"].GetString(),
            "192.168.1.1");

        auto action = e.actions.begin();
        EXPECT_EQ(e.actions.size(), 1);
        EXPECT_EQ(action->type, dds::action_type::invalid);
        EXPECT_STREQ(
            action->parameters.find("some")->second.c_str(), "parameter");
    }

    { // Using default action on waf
        std::string rules_with_actions =
            R"({"version":"2.1","rules":[{"id":"blk-001-001","name":"BlockIPAddresses","tags":{"type":"block_ip","category":"security_response"},"conditions":[{"parameters":{"inputs":[{"address":"http.client_ip"}],"data":"blocked_ips"},"operator":"ip_match"}],"transformers":[],"on_match":["block"]}], "rules_data":[{"id":"blocked_ips","type":"data_with_expiration","data":[{"value":"192.168.1.1","expiration":"9999999999"}]}]})";

        std::shared_ptr<subscriber> wi{
            waf::instance::from_string(rules_with_actions, submitm)};
        ASSERT_TRUE(wi);

        auto addresses = wi->get_subscriptions();
        EXPECT_EQ(addresses.size(), 1);
        EXPECT_STREQ(addresses.begin()->c_str(), "http.client_ip");

        auto ctx = wi->get_listener();

        dds::event e;
        ctx->call(pv, e);

        EXPECT_EQ(e.triggers.size(), 1);
        rapidjson::Document doc;
        doc.Parse(e.triggers[0]);
        EXPECT_FALSE(doc.HasParseError());
        EXPECT_TRUE(doc.IsObject());

        EXPECT_STREQ(
            doc["rule_matches"][0]["parameters"][0]["value"].GetString(),
            "192.168.1.1");

        auto action = e.actions.begin();
        EXPECT_EQ(e.actions.size(), 1);
        EXPECT_EQ(action->type, dds::action_type::block);
        EXPECT_STREQ(action->parameters.find("status_code")->second.c_str(),
            "403"); // Default value
        EXPECT_STREQ(
            action->parameters.find("grpc_status_code")->second.c_str(),
            "10"); // Default value
        EXPECT_STREQ(action->parameters.find("type")->second.c_str(),
            "auto"); // Default value
    }
}

TEST(WafTest, TelemetryIsSent)
{
    {
        NiceMock<mock::tel_submitter> submitm{};
        std::shared_ptr<subscriber> wi{
            waf::instance::from_string(waf_rule, submitm)};
        auto ctx = wi->get_listener();

        // One rasp call with match
        auto p = parameter::map();
        p.add("server.io.net.url",
            parameter::string("http://169.254.169.254?something=here"sv));
        p.add("server.request.body",
            parameter::string("http://169.254.169.254?something=here"sv));
        parameter_view pv(p);
        dds::event e;
        std::string rasp = "ssrf";
        ctx->call(pv, e, rasp);

        // Now rasp call without match
        auto p2 = parameter::map();
        parameter_view pv2(p2);
        ctx->call(pv2, e, rasp);

        // Now lfi with match
        auto p3 = parameter::map();
        p3.add("server.io.fs.file", parameter::string("../somefile"sv));
        auto query = parameter::map();
        query.add("query", parameter::string("../somefile"sv));
        p3.add("server.request.query", std::move(query));
        parameter_view pv3(p3);
        ctx->call(pv3, e, "lfi");

        parameter_view pv4;
        EXPECT_THROW(ctx->call(pv4, e, "lfi"), invalid_object);

        parameter_view pv5;
        EXPECT_THROW(ctx->call(pv5, e, "lfi"), invalid_object);

        EXPECT_CALL(submitm, submit_span_meta(metrics::event_rules_version,
                                 std::string{"1.2.3"}));
        EXPECT_CALL(submitm,
            submit_metric("waf.requests"sv, 1,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() +
                    std::string{",rule_triggered:true,waf_error:true"})));

        // SSRF
        EXPECT_CALL(submitm,
            submit_metric("rasp.rule.eval"sv, 2,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:ssrf"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.rule.match"sv, 1,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:ssrf"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.timeout"sv, 0,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:ssrf"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.error"sv, 0,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:ssrf"})));

        // // LFI
        EXPECT_CALL(submitm,
            submit_metric("rasp.rule.eval"sv, 3,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:lfi"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.rule.match"sv, 1,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:lfi"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.timeout"sv, 0,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:lfi"})));
        EXPECT_CALL(submitm,
            submit_metric("rasp.error"sv, 2,
                telemetry::telemetry_tags::from_string(
                    std::string{"event_rules_version:1.2.3,waf_version:"} +
                    ddwaf_get_version() + std::string{",rule_type:lfi"})));

        EXPECT_CALL(submitm, submit_span_metric(metrics::rasp_rule_eval, 5));
        EXPECT_CALL(submitm, submit_span_metric(metrics::waf_duration, _));
        EXPECT_CALL(submitm, submit_span_metric(metrics::rasp_duration, _));
        ctx->submit_metrics(submitm);
    }
}

TEST(WafTest, TelemetryTimeoutMetric)
{
    NiceMock<mock::tel_submitter> submitm{};
    std::shared_ptr<subscriber> wi(
        waf::instance::from_string(waf_rule, submitm, 0));
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter::string("string 1"sv));
    p.add("arg2", parameter::string("string 2"sv));

    EXPECT_CALL(submitm, submit_span_metric(metrics::rasp_timeout, 1));
    EXPECT_CALL(submitm, submit_span_metric(metrics::rasp_rule_eval, 1.0));
    // Since v1.22.0 libddwaf will still attempt to run denylists, which
    // will cause the duration to be non-zero
    EXPECT_CALL(submitm, submit_span_metric(metrics::waf_duration, _));
    EXPECT_CALL(submitm, submit_span_metric(metrics::rasp_duration, _));
    parameter_view pv(p);
    dds::event e;
    std::string rasp = "lfi";
    EXPECT_THROW(ctx->call(pv, e, rasp), timeout_error);

    EXPECT_CALL(submitm,
        submit_span_meta(metrics::event_rules_version, std::string{"1.2.3"}));
    EXPECT_CALL(submitm,
        submit_metric("waf.requests"sv, 1,
            telemetry::telemetry_tags::from_string(
                std::string{"event_rules_version:1.2.3,waf_version:"} +
                ddwaf_get_version() + std::string{",waf_timeout:true"})));

    EXPECT_CALL(
        submitm, submit_metric("rasp.rule.eval"sv, 1,
                     telemetry::telemetry_tags::from_string(
                         std::string{"event_rules_version:1.2.3,waf_version:"} +
                         ddwaf_get_version() + std::string{",rule_type:lfi"})));
    EXPECT_CALL(
        submitm, submit_metric("rasp.rule.match"sv, 0,
                     telemetry::telemetry_tags::from_string(
                         std::string{"event_rules_version:1.2.3,waf_version:"} +
                         ddwaf_get_version() + std::string{",rule_type:lfi"})));
    EXPECT_CALL(
        submitm, submit_metric("rasp.timeout"sv, 1,
                     telemetry::telemetry_tags::from_string(
                         std::string{"event_rules_version:1.2.3,waf_version:"} +
                         ddwaf_get_version() + std::string{",rule_type:lfi"})));
    EXPECT_CALL(
        submitm, submit_metric("rasp.error"sv, 0,
                     telemetry::telemetry_tags::from_string(
                         std::string{"event_rules_version:1.2.3,waf_version:"} +
                         ddwaf_get_version() + std::string{",rule_type:lfi"})));

    ctx->submit_metrics(submitm);
    Mock::VerifyAndClearExpectations(&submitm);
}

TEST(WafTest, TraceAttributesAreSent)
{
    NiceMock<mock::tel_submitter> submitm{};

    auto p = parameter::map();
    p.add("arg3", parameter::string("string 3"sv));

    auto headers = parameter::map();
    headers.add("user-agent", parameter::string("some-agent"sv));
    p.add("server.request.headers.no_cookies", std::move(headers));
    parameter_view pv(p);

    {
        std::shared_ptr<subscriber> wi{
            waf::instance::from_string(waf_rule, submitm)};
        ASSERT_TRUE(wi);

        EXPECT_CALL(
            submitm, submit_span_metric(
                         std::string_view{"_dd.appsec.trace.integer"}, 12345));
        EXPECT_CALL(
            submitm, submit_span_metric(
                         std::string_view{"_dd.appsec.trace.float"}, 12.34));
        EXPECT_CALL(
            submitm, submit_span_meta_copy_key(
                         "_dd.appsec.trace.string", std::string{"678"}));
        EXPECT_CALL(submitm, submit_span_meta_copy_key("_dd.appsec.trace.agent",
                                 std::string{"some-agent"}));
        EXPECT_CALL(submitm, submit_span_metric(metrics::waf_duration, _));
        EXPECT_CALL(submitm,
            submit_span_meta("_dd.appsec.event_rules.version", "1.2.3"));

        auto ctx = wi->get_listener();
        dds::event e;
        ctx->call(pv, e);
        ctx->submit_metrics(submitm);
        Mock::VerifyAndClearExpectations(&submitm);
    }
}
} // namespace dds
