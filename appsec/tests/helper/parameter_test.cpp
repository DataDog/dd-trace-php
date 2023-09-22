// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <exception.hpp>
#include <parameter.hpp>

namespace dds {

TEST(ParameterTest, EmptyConstructor)
{
    parameter p;
    EXPECT_EQ(p.type(), parameter_type::invalid);
    EXPECT_FALSE(p.is_valid());

    EXPECT_THROW(auto s = std::string(p), bad_cast);
    EXPECT_THROW(auto sv = std::string_view(p), bad_cast);
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);
}

TEST(ParameterTest, UintConstructor)
{
    uint64_t value = std::numeric_limits<uint64_t>::max();
    parameter p = parameter::uint64(value);
    EXPECT_EQ(p.type(), parameter_type::uint64);
    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_THROW(auto s = std::string(p), bad_cast);
    EXPECT_THROW(auto sv = std::string_view(p), bad_cast);
    EXPECT_NO_THROW(auto u64 = uint64_t(p));
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    EXPECT_EQ(value, p.uintValue);
}

TEST(ParameterTest, IntConstructor)
{
    int64_t value = std::numeric_limits<int64_t>::max();
    parameter p = parameter::int64(value);
    EXPECT_EQ(p.type(), parameter_type::int64);
    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_THROW(auto s = std::string(p), bad_cast);
    EXPECT_THROW(auto sv = std::string_view(p), bad_cast);
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_NO_THROW(auto i64 = int64_t(p));

    EXPECT_EQ(value, p.intValue);
}

TEST(ParameterTest, UintMaxConstructorAsString)
{
    uint64_t value = std::numeric_limits<uint64_t>::max();
    parameter p = parameter::string(value);
    EXPECT_EQ(p.type(), parameter_type::string);
    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_NO_THROW(auto s = std::string(p));
    EXPECT_NO_THROW(auto sv = std::string_view(p));
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    std::stringstream ss;
    ss << value;
    const auto &value_str = ss.str();
    EXPECT_STREQ(p.stringValue, value_str.c_str());
    EXPECT_STREQ(std::string_view(p).data(), value_str.c_str());
}

TEST(ParameterTest, UintMinConstructorAsString)
{
    uint64_t value = std::numeric_limits<uint64_t>::min();
    parameter p = parameter::string(value);
    EXPECT_EQ(p.type(), parameter_type::string);
    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_NO_THROW(auto s = std::string(p));
    EXPECT_NO_THROW(auto sv = std::string_view(p));
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    std::stringstream ss;
    ss << value;
    const auto &value_str = ss.str();
    EXPECT_STREQ(p.stringValue, value_str.c_str());
    EXPECT_STREQ(std::string_view(p).data(), value_str.c_str());
}

TEST(ParameterTest, IntMaxConstructorAsString)
{
    int64_t value = std::numeric_limits<int64_t>::max();
    parameter p = parameter::string(value);
    EXPECT_EQ(p.type(), parameter_type::string);
    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_NO_THROW(auto s = std::string(p));
    EXPECT_NO_THROW(auto sv = std::string_view(p));
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    std::stringstream ss;
    ss << value;
    const auto &value_str = ss.str();
    EXPECT_STREQ(p.stringValue, value_str.c_str());
    EXPECT_STREQ(std::string_view(p).data(), value_str.c_str());
}

TEST(ParameterTest, IntMinConstructorAsString)
{
    int64_t value = std::numeric_limits<int64_t>::min();
    parameter p = parameter::string(value);
    EXPECT_EQ(p.type(), parameter_type::string);
    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_NO_THROW(auto s = std::string(p));
    EXPECT_NO_THROW(auto sv = std::string_view(p));
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    std::stringstream ss;
    ss << value;
    const auto &value_str = ss.str();
    EXPECT_STREQ(p.stringValue, value_str.c_str());
    EXPECT_STREQ(std::string_view(p).data(), value_str.c_str());
}

TEST(ParameterTest, StringConstructor)
{
    std::string value("thisisastring");
    parameter p = parameter::string(value);
    EXPECT_EQ(p.type(), parameter_type::string);
    EXPECT_EQ(p.length(), value.size());
    EXPECT_EQ(p.size(), 0);

    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_NO_THROW(auto s = std::string(p));
    EXPECT_NO_THROW(auto sv = std::string_view(p));
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    EXPECT_STREQ(p.stringValue, value.data());
    EXPECT_STREQ(std::string_view(p).data(), value.data());
}

TEST(ParameterTest, StringViewConstructor)
{
    std::string value("thisisastring");
    parameter p = parameter::string(value);
    EXPECT_EQ(p.type(), parameter_type::string);
    EXPECT_EQ(p.length(), value.size());
    EXPECT_EQ(p.size(), 0);

    EXPECT_NE(p.stringValue, nullptr);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_NO_THROW(auto s = std::string(p));
    EXPECT_NO_THROW(auto sv = std::string_view(p));
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    EXPECT_STREQ(p.stringValue, value.data());
    EXPECT_STREQ(std::string_view(p).data(), value.data());
}

TEST(ParameterTest, MoveConstructor)
{
    parameter p = parameter::string("thisisastring"sv);
    parameter pcopy(std::move(p));

    EXPECT_EQ(pcopy.type(), parameter_type::string);
    EXPECT_STREQ(pcopy.stringValue, "thisisastring");

    EXPECT_FALSE(p.is_valid());
}

TEST(ParameterTest, ObjectConstructor)
{
    ddwaf_object pw{};
    pw.parameterName = strdup("param");
    pw.parameterNameLength = sizeof("param") - 1;
    pw.stringValue = strdup("stringValue");
    pw.ddwaf_object::type = DDWAF_OBJ_STRING;

    parameter p(pw);
    EXPECT_EQ(p.parameterName, pw.parameterName);
    EXPECT_EQ(p.parameterNameLength, pw.parameterNameLength);
    EXPECT_EQ(p.stringValue, pw.stringValue);
    EXPECT_EQ(p.type(), pw.type);
}

TEST(ParameterTest, Map)
{
    parameter p = parameter::map();
    EXPECT_EQ(p.type(), parameter_type::map);
    EXPECT_EQ(p.size(), 0);
    EXPECT_EQ(p.length(), 0);

    EXPECT_TRUE(p.add("key0", parameter::string("value"sv)));
    EXPECT_STREQ(p[0].key().data(), "key0");
    EXPECT_EQ(p.size(), 1);
    EXPECT_EQ(p.length(), 0);

    EXPECT_TRUE(p.add("key1", parameter::string("value"sv)));
    EXPECT_STREQ(p[1].key().data(), "key1");
    EXPECT_EQ(p.size(), 2);
    EXPECT_EQ(p.length(), 0);

    EXPECT_TRUE(p.add("key2", parameter::string("value"sv)));
    EXPECT_STREQ(p[2].key().data(), "key2");
    EXPECT_EQ(p.size(), 3);
    EXPECT_EQ(p.length(), 0);

    EXPECT_TRUE(p.add("key3", parameter::string("value"sv)));
    EXPECT_STREQ(p[3].key().data(), "key3");
    EXPECT_EQ(p.size(), 4);
    EXPECT_EQ(p.length(), 0);

    auto v = parameter::string("value"sv);
    EXPECT_TRUE(p.add("key4", std::move(v)));
    EXPECT_STREQ(p[4].key().data(), "key4");
    EXPECT_EQ(p.size(), 5);
    EXPECT_EQ(p.length(), 0);

    EXPECT_FALSE(p.add(parameter::string("value"sv)));

    v = parameter::string("value"sv);
    EXPECT_FALSE(p.add(std::move(v)));

    EXPECT_THROW(auto s = std::string(p), bad_cast);
    EXPECT_THROW(auto sv = std::string_view(p), bad_cast);
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);
}

TEST(ParameterTest, Array)
{
    parameter p = parameter::array();
    EXPECT_EQ(p.type(), parameter_type::array);
    EXPECT_EQ(p.size(), 0);
    EXPECT_EQ(p.length(), 0);

    EXPECT_TRUE(p.add(parameter::string("value"sv)));
    EXPECT_EQ(p.size(), 1);
    EXPECT_EQ(p.length(), 0);

    EXPECT_TRUE(p.add(parameter::string("value"sv)));
    EXPECT_EQ(p.size(), 2);
    EXPECT_EQ(p.length(), 0);

    EXPECT_TRUE(p.add(parameter::string("value"sv)));
    EXPECT_EQ(p.size(), 3);
    EXPECT_EQ(p.length(), 0);

    EXPECT_TRUE(p.add(parameter::string("value"sv)));
    EXPECT_EQ(p.size(), 4);
    EXPECT_EQ(p.length(), 0);

    auto v = parameter::string("value"sv);
    EXPECT_TRUE(p.add(std::move(v)));
    EXPECT_EQ(p.size(), 5);
    EXPECT_EQ(p.length(), 0);

    EXPECT_FALSE(p.add("key", parameter::string("value"sv)));

    v = parameter::string("value"sv);
    EXPECT_FALSE(p.add("key", std::move(v)));

    EXPECT_THROW(auto s = std::string(p), bad_cast);
    EXPECT_THROW(auto sv = std::string_view(p), bad_cast);
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);
}

TEST(ParameterTest, StaticCastFromMapObject)
{
    int size = 40;
    ddwaf_object obj, tmp;
    ddwaf_object_map(&obj);
    for (int i = 0; i < size; i++) {
        ddwaf_object_map_add(&obj, std::to_string(i).c_str(),
            ddwaf_object_string(&tmp, "value"));
    }

    parameter p = static_cast<parameter>(obj);
    ;
    EXPECT_EQ(p.type(), parameter_type::map);
    EXPECT_EQ(p.length(), 0);
    EXPECT_EQ(p.size(), size);

    for (int i = 0; i < size; i++) {
        EXPECT_TRUE(p[i].is_valid());
        EXPECT_TRUE(p[i].is_string());
        EXPECT_STREQ(p[i].key().data(), std::to_string(i).c_str());
        EXPECT_STREQ(std::string_view(p[i]).data(), "value");
    }

    EXPECT_THROW(p[size], std::out_of_range);
}

TEST(ParameterTest, StaticCastFromArrayObject)
{
    int size = 40;
    ddwaf_object obj, tmp;
    ddwaf_object_array(&obj);
    for (int i = 0; i < size; i++) {
        ddwaf_object_array_add(
            &obj, ddwaf_object_string(&tmp, std::to_string(i).c_str()));
    }

    parameter p = static_cast<parameter>(obj);
    EXPECT_EQ(p.type(), parameter_type::array);
    EXPECT_EQ(p.length(), 0);
    EXPECT_EQ(p.size(), size);

    for (int i = 0; i < size; i++) {
        EXPECT_TRUE(p[i].is_valid());
        EXPECT_TRUE(p[i].is_string());
        EXPECT_STREQ(std::string_view(p[i]).data(), std::to_string(i).c_str());
    }

    EXPECT_THROW(p[size], std::out_of_range);
}

} // namespace dds
