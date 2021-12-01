// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include <subscriber/waf.hpp>
#include <rapidjson/document.h>
#include "common.hpp"

const std::string waf_rule =
    R"({"version":"2.1","rules":[{"id":"1","name":"rule1","tags":{"type":"flow1","category":"category1"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg1","key_path":[]}],"regex":"^string.*"}},{"operator":"match_regex","parameters":{"inputs":[{"address":"arg2","key_path":[]}],"regex":".*"}}],"action":"record"}]})";

namespace dds {

TEST(WafTest, RunWithInvalidParam) {
    subscriber::ptr wi(waf::instance::from_string(waf_rule));
    auto ctx = wi->get_listener();
    parameter p;
    EXPECT_THROW(ctx->call(p, dds::engine::default_timeout), invalid_object);
    p.free();
}

TEST(WafTest, RunWithTimeout) {
    subscriber::ptr wi(waf::instance::from_string(waf_rule));
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter("string 1"sv));
    p.add("arg2", parameter("string 2"sv));

    EXPECT_THROW(ctx->call(p, 0), timeout_error);
    p.free();
}

TEST(WafTest, ValidRunGood) {
    subscriber::ptr wi(waf::instance::from_string(waf_rule));
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter("string 1"sv));

    auto res = ctx->call(p, dds::engine::default_timeout);
    p.free();
    EXPECT_EQ(res.value, dds::result::code::ok);
}

TEST(WafTest, ValidRunMonitor) {
    subscriber::ptr wi(waf::instance::from_string(waf_rule));
    auto ctx = wi->get_listener();

    auto p = parameter::map();
    p.add("arg1", parameter("string 1"sv));
    p.add("arg2", parameter("string 3"sv));

    auto res = ctx->call(p, dds::engine::default_timeout);
    p.free();
    EXPECT_EQ(res.value, dds::result::code::record);

    for (auto &match : res.data) {
        rapidjson::Document doc;
        doc.Parse(match);
        EXPECT_FALSE(doc.HasParseError());
        EXPECT_TRUE(doc.IsObject());
    }
}

} // namespace dds
