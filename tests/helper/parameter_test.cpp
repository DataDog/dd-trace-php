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
    p.free();
}

TEST(ParameterTest, UintMaxConstructor)
{
    uint64_t value = std::numeric_limits<uint64_t>::max();
    parameter p(value);
    EXPECT_EQ(p.type, DDWAF_OBJ_STRING);
    EXPECT_NE(p.stringValue, nullptr);

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

    EXPECT_TRUE(p.stringValue == value);
    p.free();
}

TEST(ParameterTest, StringViewConstructor)
{
    parameter p("thisisastring"sv);
    EXPECT_EQ(p.type, DDWAF_OBJ_STRING);
    EXPECT_NE(p.stringValue, nullptr);

    EXPECT_TRUE(p.stringValue == "thisisastring"sv);
    p.free();
}

TEST(ParameterTest, MoveConstructor)
{
    parameter p("thisisastring"sv);
    parameter pcopy(std::move(p));

    EXPECT_EQ(pcopy.type, DDWAF_OBJ_STRING);
    EXPECT_STREQ(pcopy.stringValue, "thisisastring");

    EXPECT_EQ(p.type, DDWAF_OBJ_INVALID);
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
    EXPECT_EQ(p.nbEntries, 0);

    EXPECT_TRUE(p.add("key", parameter("value"sv)));
    EXPECT_EQ(p.nbEntries, 1);

    EXPECT_TRUE(p.add("key", parameter("value"sv)));
    EXPECT_EQ(p.nbEntries, 2);

    EXPECT_TRUE(p.add("key", parameter("value"sv)));
    EXPECT_EQ(p.nbEntries, 3);

    EXPECT_TRUE(p.add("key", parameter("value"sv)));
    EXPECT_EQ(p.nbEntries, 4);

    EXPECT_FALSE(p.add(parameter("value"sv)));

    p.free();
}

TEST(ParameterTest, Array)
{
    parameter p = parameter::array();
    EXPECT_EQ(p.type, DDWAF_OBJ_ARRAY);
    EXPECT_EQ(p.nbEntries, 0);

    EXPECT_TRUE(p.add(parameter("value"sv)));
    EXPECT_EQ(p.nbEntries, 1);

    EXPECT_TRUE(p.add(parameter("value"sv)));
    EXPECT_EQ(p.nbEntries, 2);

    EXPECT_TRUE(p.add(parameter("value"sv)));
    EXPECT_EQ(p.nbEntries, 3);

    EXPECT_TRUE(p.add(parameter("value"sv)));
    EXPECT_EQ(p.nbEntries, 4);

    EXPECT_FALSE(p.add("key", parameter("value"sv)));

    p.free();
}

} // namespace dds
