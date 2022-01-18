// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <parameter.hpp>

const std::string waf_rule =
    R"({"version":"1.0","events":[{"id":1,"tags":{"type":"flow1"},"conditions":[{"operation":"match_regex","parameters":{"inputs":["arg1"],"regex":"^string.*"}},{"operation":"match_regex","parameters":{"inputs":["arg2"],"regex":".*"}}],"action":"record"}]})";

namespace dds {

TEST(ParameterTest, EmptyConstructor)
{
    parameter p;
    EXPECT_EQ(p.type, DDWAF_OBJ_INVALID);
    EXPECT_FALSE(p.is_valid());
    p.free();
}

TEST(ParameterTest, UintMaxConstructor)
{
    uint64_t value = std::numeric_limits<uint64_t>::max();
    parameter p(value);
    EXPECT_EQ(p.type, DDWAF_OBJ_STRING);
    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_FALSE(p[0].is_valid());

    std::stringstream ss;
    ss << value;
    EXPECT_TRUE(p.stringValue == ss.str());
    p.free();
}

TEST(ParameterTest, UintMinConstructor)
{
    uint64_t value = std::numeric_limits<uint64_t>::min();
    parameter p(value);
    EXPECT_EQ(p.type, DDWAF_OBJ_STRING);
    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_FALSE(p[0].is_valid());

    std::stringstream ss;
    ss << value;
    EXPECT_TRUE(p.stringValue == ss.str());
    p.free();
}

TEST(ParameterTest, IntMaxConstructor)
{
    int64_t value = std::numeric_limits<int64_t>::max();
    parameter p(value);
    EXPECT_EQ(p.type, DDWAF_OBJ_STRING);
    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_FALSE(p[0].is_valid());

    std::stringstream ss;
    ss << value;
    EXPECT_TRUE(p.stringValue == ss.str());
    p.free();
    
}

TEST(ParameterTest, IntMinConstructor)
{
    int64_t value = std::numeric_limits<int64_t>::min();
    parameter p(value);
    EXPECT_EQ(p.type, DDWAF_OBJ_STRING);
    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_FALSE(p[0].is_valid());

    std::stringstream ss;
    ss << value;
    EXPECT_TRUE(p.stringValue == ss.str());
    p.free();
}

TEST(ParameterTest, StringConstructor)
{
    std::string value("thisisastring");
    parameter p(value);
    EXPECT_EQ(p.type, DDWAF_OBJ_STRING);
    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_FALSE(p[0].is_valid());

    EXPECT_TRUE(p.stringValue == value);
    p.free();
}

TEST(ParameterTest, StringViewConstructor)
{
    parameter p("thisisastring"sv);
    EXPECT_EQ(p.type, DDWAF_OBJ_STRING);
    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_FALSE(p[0].is_valid());

    EXPECT_TRUE(p.stringValue == "thisisastring"sv);
    p.free();
}

TEST(ParameterTest, MoveConstructor)
{
    parameter p("thisisastring"sv);
    parameter pcopy(std::move(p));

    EXPECT_EQ(pcopy.type, DDWAF_OBJ_STRING);
    EXPECT_STREQ(pcopy.stringValue, "thisisastring");

    EXPECT_FALSE(p.is_valid());
    pcopy.free();
}

TEST(ParameterTest, ObjectConstructor)
{
    ddwaf_object pw{};
    pw.parameterName = "param";
    pw.parameterNameLength = sizeof("param") - 1;
    pw.stringValue = "stringValue";
    pw.type = DDWAF_OBJ_STRING;

    parameter p(pw);
    EXPECT_EQ(p.parameterName, pw.parameterName);
    EXPECT_EQ(p.parameterNameLength, pw.parameterNameLength);
    EXPECT_EQ(p.stringValue, pw.stringValue);
    EXPECT_EQ(p.type, pw.type);

    // Freeing would attempt an invalid free on string literals.
}

TEST(ParameterTest, Map)
{
    parameter p = parameter::map();
    EXPECT_EQ(p.type, DDWAF_OBJ_MAP);
    EXPECT_EQ(p.size(), 0);

    EXPECT_TRUE(p.add("key0", parameter("value"sv)));
    EXPECT_STREQ(p[0].key().data(), "key0");
    EXPECT_EQ(p.size(), 1);

    EXPECT_TRUE(p.add("key1", parameter("value"sv)));
    EXPECT_STREQ(p[1].key().data(), "key1");
    EXPECT_EQ(p.size(), 2);

    EXPECT_TRUE(p.add("key2", parameter("value"sv)));
    EXPECT_STREQ(p[2].key().data(), "key2");
    EXPECT_EQ(p.size(), 3);

    EXPECT_TRUE(p.add("key3", parameter("value"sv)));
    EXPECT_STREQ(p[3].key().data(), "key3");
    EXPECT_EQ(p.size(), 4);

    // const ref test
    auto v = parameter("value"sv);
    EXPECT_TRUE(p.add("key4", v));
    EXPECT_STREQ(p[4].key().data(), "key4");
    EXPECT_EQ(p.size(), 5);

    EXPECT_FALSE(p.add(parameter("value"sv)));
    EXPECT_FALSE(p.add(v));

    EXPECT_STREQ(std::string_view(p).data(), nullptr);

    p.free();
}

TEST(ParameterTest, Array)
{
    parameter p = parameter::array();
    EXPECT_EQ(p.type, DDWAF_OBJ_ARRAY);
    EXPECT_EQ(p.size(), 0);

    EXPECT_TRUE(p.add(parameter("value"sv)));
    EXPECT_EQ(p.size(), 1);

    EXPECT_TRUE(p.add(parameter("value"sv)));
    EXPECT_EQ(p.size(), 2);

    EXPECT_TRUE(p.add(parameter("value"sv)));
    EXPECT_EQ(p.size(), 3);

    EXPECT_TRUE(p.add(parameter("value"sv)));
    EXPECT_EQ(p.size(), 4);

    // const ref test
    auto v = parameter("value"sv);
    EXPECT_TRUE(p.add(v));
    EXPECT_EQ(p.size(), 5);

    EXPECT_FALSE(p.add("key", parameter("value"sv)));
    EXPECT_FALSE(p.add("key", v));

    EXPECT_STREQ(std::string_view(p).data(), nullptr);
    p.free();
}

} // namespace dds
