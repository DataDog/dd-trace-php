// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <libddwaf/src/log.hpp>

#include "client_settings.hpp"
#include "common.hpp"
#include <defer.hpp>
#include <rapidjson/document.h>
#include <spdlog/details/null_mutex.h>
#include <spdlog/sinks/base_sink.h>
#include <subscriber/waf.hpp>
#include <tags.hpp>

const std::string waf_rule =
    R"({"version":"2.1","metadata":{"rules_version":"1.2.3"},"rules":[{"id":"1","name":"rule1","tags":{"type":"flow1","category":"category1"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg1","key_path":[]}],"regex":"^string.*"}},{"operator":"match_regex","parameters":{"inputs":[{"address":"arg2","key_path":[]}],"regex":".*"}}],"action":"record"}]})";

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
    client_settings cs;
    cs.rules_file = create_sample_rules_invalid();

    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    subscriber::ptr wi{waf::instance::from_settings(cs, meta, metrics)};

    EXPECT_EQ(meta.size(), 2);
    EXPECT_STREQ(meta[tag::waf_version].c_str(), "1.6.0");

    rapidjson::Document doc;
    doc.Parse(meta[tag::event_rules_errors]);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());
    EXPECT_TRUE(doc.HasMember("missing key 'type'"));
    EXPECT_TRUE(doc.HasMember("unknown processor: squash"));
    EXPECT_TRUE(doc.HasMember("missing key 'inputs'"));

    EXPECT_EQ(metrics.size(), 2);
    // For small enough integers this comparison should work, otherwise replace
    // with EXPECT_NEAR.
    EXPECT_EQ(metrics[tag::event_rules_loaded], 1.0);
    EXPECT_EQ(metrics[tag::event_rules_failed], 4.0);
}

TEST(WafTest, RunWithInvalidParam)
{
    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    subscriber::ptr wi{waf::instance::from_string(waf_rule, meta, metrics)};
    auto ctx = wi->get_listener();
    parameter_view pv;
    EXPECT_THROW(ctx->call(pv), invalid_object);
}

TEST(WafTest, RunWithTimeout)
{
    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    subscriber::ptr wi(waf::instance::from_string(waf_rule, meta, metrics, 0));
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter::string("string 1"sv));
    p.add("arg2", parameter::string("string 2"sv));

    parameter_view pv(p);
    EXPECT_THROW(ctx->call(pv), timeout_error);
}

TEST(WafTest, ValidRunGood)
{
    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    subscriber::ptr wi{waf::instance::from_string(waf_rule, meta, metrics)};
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter::string("string 1"sv));

    parameter_view pv(p);
    auto res = ctx->call(pv);
    EXPECT_EQ(res.value, dds::result::code::ok);

    ctx->get_meta_and_metrics(meta, metrics);
    EXPECT_STREQ(meta[tag::event_rules_version].c_str(), "1.2.3");
    EXPECT_GT(metrics[tag::waf_duration], 0.0);
}

TEST(WafTest, ValidRunMonitor)
{
    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    subscriber::ptr wi{waf::instance::from_string(waf_rule, meta, metrics)};
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter::string("string 1"sv));
    p.add("arg2", parameter::string("string 3"sv));

    parameter_view pv(p);
    auto res = ctx->call(pv);
    EXPECT_EQ(res.value, dds::result::code::record);

    for (auto &match : res.data) {
        rapidjson::Document doc;
        doc.Parse(match);
        EXPECT_FALSE(doc.HasParseError());
        EXPECT_TRUE(doc.IsObject());
    }

    ctx->get_meta_and_metrics(meta, metrics);
    EXPECT_STREQ(meta[tag::event_rules_version].c_str(), "1.2.3");
    EXPECT_GT(metrics[tag::waf_duration], 0.0);
}

TEST(WafTest, ValidRunMonitorObfuscated)
{
    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    subscriber::ptr wi{waf::instance::from_string(waf_rule, meta, metrics,
        waf::instance::default_waf_timeout_us, "password"sv, "string 3"sv)};
    auto ctx = wi->get_listener();

    auto p = parameter::map(), sub_p = parameter::map();
    sub_p.add("password", parameter::string("string 1"sv));
    p.add("arg1", std::move(sub_p));
    p.add("arg2", parameter::string("string 3"sv));

    parameter_view pv(p);
    auto res = ctx->call(pv);
    EXPECT_EQ(res.value, dds::result::code::record);

    EXPECT_EQ(res.data.size(), 1);
    rapidjson::Document doc;
    doc.Parse(res.data[0]);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());

    EXPECT_STREQ(doc["rule_matches"][0]["parameters"][0]["value"].GetString(),
        "<Redacted>");
    EXPECT_STREQ(doc["rule_matches"][1]["parameters"][0]["value"].GetString(),
        "<Redacted>");

    ctx->get_meta_and_metrics(meta, metrics);
    EXPECT_STREQ(meta[tag::event_rules_version].c_str(), "1.2.3");
    EXPECT_GT(metrics[tag::waf_duration], 0.0);
}

TEST(WafTest, ValidRunMonitorObfuscatedFromSettings)
{
    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    client_settings cs;
    cs.rules_file = create_sample_rules_ok();
    cs.obfuscator_key_regex = "password";

    subscriber::ptr wi{waf::instance::from_settings(cs, meta, metrics)};

    auto ctx = wi->get_listener();

    auto p = parameter::map(), sub_p = parameter::map();
    sub_p.add("password", parameter::string("acunetix-product"sv));
    p.add("server.request.headers.no_cookies", std::move(sub_p));

    parameter_view pv(p);
    auto res = ctx->call(pv);
    EXPECT_EQ(res.value, dds::result::code::record);

    EXPECT_EQ(res.data.size(), 1);
    rapidjson::Document doc;
    doc.Parse(res.data[0]);
    EXPECT_FALSE(doc.HasParseError());
    EXPECT_TRUE(doc.IsObject());

    EXPECT_STREQ(doc["rule_matches"][0]["parameters"][0]["value"].GetString(),
        "<Redacted>");

    ctx->get_meta_and_metrics(meta, metrics);
    EXPECT_STREQ(meta[tag::event_rules_version].c_str(), "1.2.3");
    EXPECT_GT(metrics[tag::waf_duration], 0.0);
}

TEST(WafTest, Logging)
{
    auto d = defer([old_logger = spdlog::default_logger()]() {
        spdlog::set_default_logger(old_logger);
    });

    auto sink = std::make_shared<log_counter_sink_st>();
    auto logger = std::make_shared<spdlog::logger>("ddappsec_test", sink);

    spdlog::set_default_logger(logger);
    spdlog::set_level(spdlog::level::trace);

    {
        dds::waf::initialise_logging(spdlog::level::off);
        DDWAF_TRACE("trace");
        DDWAF_DEBUG("debug");
        DDWAF_INFO("info");
        DDWAF_WARN("warn");
        DDWAF_ERROR("error");
        EXPECT_EQ(sink->count(), 0);
    }

    {
        dds::waf::initialise_logging(spdlog::level::err);
        DDWAF_TRACE("trace");
        DDWAF_DEBUG("debug");
        DDWAF_INFO("info");
        DDWAF_WARN("warn");
        DDWAF_ERROR("error");
        EXPECT_EQ(sink->count(), 1);
        sink->clear();
    }

    {
        dds::waf::initialise_logging(spdlog::level::warn);
        DDWAF_TRACE("trace");
        DDWAF_DEBUG("debug");
        DDWAF_INFO("info");
        DDWAF_WARN("warn");
        DDWAF_ERROR("error");
        EXPECT_EQ(sink->count(), 2);
        sink->clear();
    }

    // Count the extra info message from the WAF "Sending log messages..."
    {
        dds::waf::initialise_logging(spdlog::level::info);
        DDWAF_TRACE("trace");
        DDWAF_DEBUG("debug");
        DDWAF_INFO("info");
        DDWAF_WARN("warn");
        DDWAF_ERROR("error");
        EXPECT_EQ(sink->count(), 4);
        sink->clear();
    }

    {
        dds::waf::initialise_logging(spdlog::level::debug);
        DDWAF_TRACE("trace");
        DDWAF_DEBUG("debug");
        DDWAF_INFO("info");
        DDWAF_WARN("warn");
        DDWAF_ERROR("error");
        EXPECT_EQ(sink->count(), 5);
        sink->clear();
    }

    {
        dds::waf::initialise_logging(spdlog::level::trace);
        DDWAF_TRACE("trace");
        DDWAF_DEBUG("debug");
        DDWAF_INFO("info");
        DDWAF_WARN("warn");
        DDWAF_ERROR("error");
        EXPECT_EQ(sink->count(), 6);
        sink->clear();
    }
}

} // namespace dds
