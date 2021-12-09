// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <libddwaf/src/log.hpp>

#include "common.hpp"
#include <defer.hpp>
#include <rapidjson/document.h>
#include <spdlog/details/null_mutex.h>
#include <spdlog/sinks/base_sink.h>
#include <subscriber/waf.hpp>

const std::string waf_rule =
    R"({"version":"2.1","rules":[{"id":"1","name":"rule1","tags":{"type":"flow1","category":"category1"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg1","key_path":[]}],"regex":"^string.*"}},{"operator":"match_regex","parameters":{"inputs":[{"address":"arg2","key_path":[]}],"regex":".*"}}],"action":"record"}]})";

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

TEST(WafTest, RunWithInvalidParam)
{
    subscriber::ptr wi(waf::instance::from_string(
        waf_rule, dds::engine::default_waf_timeout_ms));
    auto ctx = wi->get_listener();
    parameter p;
    EXPECT_THROW(ctx->call(p), invalid_object);
    p.free();
}

TEST(WafTest, RunWithTimeout)
{
    subscriber::ptr wi(waf::instance::from_string(waf_rule, 0));
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter("string 1"sv));
    p.add("arg2", parameter("string 2"sv));

    EXPECT_THROW(ctx->call(p), timeout_error);
    p.free();
}

TEST(WafTest, ValidRunGood)
{
    subscriber::ptr wi(
        waf::instance::from_string(waf_rule, engine::default_waf_timeout_ms));
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter("string 1"sv));

    auto res = ctx->call(p);
    p.free();
    EXPECT_EQ(res.value, dds::result::code::ok);
}

TEST(WafTest, ValidRunMonitor)
{
    subscriber::ptr wi(
        waf::instance::from_string(waf_rule, engine::default_waf_timeout_ms));
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter("string 1"sv));
    p.add("arg2", parameter("string 3"sv));

    auto res = ctx->call(p);
    p.free();
    EXPECT_EQ(res.value, dds::result::code::record);

    for (auto &match : res.data) {
        rapidjson::Document doc;
        doc.Parse(match);
        EXPECT_FALSE(doc.HasParseError());
        EXPECT_TRUE(doc.IsObject());
    }
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
