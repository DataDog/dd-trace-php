// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <exception.hpp>
#include <parameter.hpp>
#include <parameter_view.hpp>

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
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_THROW(auto s = std::string(p), bad_cast);
    EXPECT_THROW(auto sv = std::string_view(p), bad_cast);
    EXPECT_NO_THROW(auto u64 = uint64_t(p));
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    EXPECT_EQ(value, ddwaf_object_get_unsigned(&p));
}

TEST(ParameterTest, IntConstructor)
{
    int64_t value = std::numeric_limits<int64_t>::max();
    parameter p = parameter::int64(value);
    EXPECT_EQ(p.type(), parameter_type::int64);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_THROW(auto s = std::string(p), bad_cast);
    EXPECT_THROW(auto sv = std::string_view(p), bad_cast);
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_NO_THROW(auto i64 = int64_t(p));

    EXPECT_EQ(value, ddwaf_object_get_signed(&p));
}

TEST(ParameterTest, UintMaxConstructorAsString)
{
    uint64_t value = std::numeric_limits<uint64_t>::max();
    parameter p = parameter::string(value);
    EXPECT_EQ(p.type(), parameter_type::string);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_NO_THROW(auto s = std::string(p));
    EXPECT_NO_THROW(auto sv = std::string_view(p));
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    std::stringstream ss;
    ss << value;
    const auto &value_str = ss.str();
    size_t len;
    const char *str = ddwaf_object_get_string(&p, &len);
    EXPECT_EQ(std::string_view(str, len), value_str);
    EXPECT_EQ(std::string_view(p), value_str);
}

TEST(ParameterTest, UintMinConstructorAsString)
{
    uint64_t value = std::numeric_limits<uint64_t>::min();
    parameter p = parameter::string(value);
    EXPECT_EQ(p.type(), parameter_type::string);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_NO_THROW(auto s = std::string(p));
    EXPECT_NO_THROW(auto sv = std::string_view(p));
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    std::stringstream ss;
    ss << value;
    const auto &value_str = ss.str();
    size_t len;
    const char *str = ddwaf_object_get_string(&p, &len);
    EXPECT_EQ(std::string_view(str, len), value_str);
    EXPECT_EQ(std::string_view(p), value_str);
}

TEST(ParameterTest, IntMaxConstructorAsString)
{
    int64_t value = std::numeric_limits<int64_t>::max();
    parameter p = parameter::string(value);
    EXPECT_EQ(p.type(), parameter_type::string);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_NO_THROW(auto s = std::string(p));
    EXPECT_NO_THROW(auto sv = std::string_view(p));
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    std::stringstream ss;
    ss << value;
    const auto &value_str = ss.str();
    size_t len;
    const char *str = ddwaf_object_get_string(&p, &len);
    EXPECT_EQ(std::string_view(str, len), value_str);
    EXPECT_EQ(std::string_view(p), value_str);
}

TEST(ParameterTest, IntMinConstructorAsString)
{
    int64_t value = std::numeric_limits<int64_t>::min();
    parameter p = parameter::string(value);
    EXPECT_EQ(p.type(), parameter_type::string);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_NO_THROW(auto s = std::string(p));
    EXPECT_NO_THROW(auto sv = std::string_view(p));
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    std::stringstream ss;
    ss << value;
    const auto &value_str = ss.str();
    size_t len;
    const char *str = ddwaf_object_get_string(&p, &len);
    EXPECT_EQ(std::string_view(str, len), value_str);
    EXPECT_EQ(std::string_view(p), value_str);
}

TEST(ParameterTest, StringConstructor)
{
    std::string value("thisisastring");
    parameter p = parameter::string(value);
    EXPECT_EQ(p.type(), parameter_type::string);
    EXPECT_EQ(p.length(), value.size());
    EXPECT_EQ(p.size(), 0);

    EXPECT_THROW(p[0], invalid_type);

    EXPECT_NO_THROW(auto s = std::string(p));
    EXPECT_NO_THROW(auto sv = std::string_view(p));
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    size_t len2;
    const char *str2 = ddwaf_object_get_string(&p, &len2);
    EXPECT_STREQ(str2, value.data());
    EXPECT_STREQ(std::string_view(p).data(), value.data());
}

TEST(ParameterTest, StringViewConstructor)
{
    std::string value("thisisastring");
    parameter p = parameter::string(value);
    EXPECT_EQ(p.type(), parameter_type::string);
    EXPECT_EQ(p.length(), value.size());
    EXPECT_EQ(p.size(), 0);

    EXPECT_THROW(p[0], invalid_type);

    EXPECT_NO_THROW(auto s = std::string(p));
    EXPECT_NO_THROW(auto sv = std::string_view(p));
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    size_t len2;
    const char *str2 = ddwaf_object_get_string(&p, &len2);
    EXPECT_STREQ(str2, value.data());
    EXPECT_STREQ(std::string_view(p).data(), value.data());
}

TEST(ParameterTest, MoveConstructor)
{
    parameter p = parameter::string("thisisastring"sv);
    parameter pcopy(std::move(p));

    EXPECT_EQ(pcopy.type(), parameter_type::string);
    size_t len3;
    const char *str3 = ddwaf_object_get_string(&pcopy, &len3);
    EXPECT_STREQ(str3, "thisisastring");

    EXPECT_FALSE(p.is_valid());
}

TEST(ParameterTest, Map)
{
    parameter p = parameter::map();
    EXPECT_EQ(p.type(), parameter_type::map);
    EXPECT_EQ(p.size(), 0);
    EXPECT_EQ(p.length(), 0);

    EXPECT_TRUE(p.add("key0", parameter::string("value"sv)));
    EXPECT_EQ(p.size(), 1);
    EXPECT_EQ(p.length(), 0);

    EXPECT_TRUE(p.add("key1", parameter::string("value"sv)));
    EXPECT_EQ(p.size(), 2);
    EXPECT_EQ(p.length(), 0);

    EXPECT_TRUE(p.add("key2", parameter::string("value"sv)));
    EXPECT_EQ(p.size(), 3);
    EXPECT_EQ(p.length(), 0);

    EXPECT_TRUE(p.add("key3", parameter::string("value"sv)));
    EXPECT_EQ(p.size(), 4);
    EXPECT_EQ(p.length(), 0);

    auto v = parameter::string("value"sv);
    EXPECT_TRUE(p.add("key4", std::move(v)));
    EXPECT_EQ(p.size(), 5);
    EXPECT_EQ(p.length(), 0);

    // Verify keys using map iteration
    parameter_view pv{*&p};
    auto map_it = pv.map_iterable();
    int idx = 0;
    for (const auto &[key, value] : map_it) {
        EXPECT_STREQ(key.data(), ("key" + std::to_string(idx)).c_str());
        idx++;
    }

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
    ddwaf_object obj;
    auto alloc = ddwaf_get_default_allocator();
    ddwaf_object_set_map(&obj, size, alloc);
    for (int i = 0; i < size; i++) {
        auto key = std::to_string(i);
        ddwaf_object *elem =
            ddwaf_object_insert_key(&obj, key.c_str(), key.length(), alloc);
        ddwaf_object_set_string(elem, "value", 5, alloc);
    }

    parameter p = static_cast<parameter>(obj);
    EXPECT_EQ(p.type(), parameter_type::map);
    EXPECT_EQ(p.length(), 0);
    EXPECT_EQ(p.size(), size);

    parameter_view pv{*&p};
    auto map_it = pv.map_iterable();
    int idx = 0;
    for (const auto &[key, value] : map_it) {
        EXPECT_TRUE(value.is_valid());
        EXPECT_TRUE(value.is_string());
        EXPECT_STREQ(key.data(), std::to_string(idx).c_str());
        EXPECT_STREQ(std::string_view(value).data(), "value");
        idx++;
    }

    EXPECT_THROW(p[size], std::out_of_range);
}

TEST(ParameterTest, StaticCastFromArrayObject)
{
    int size = 40;
    ddwaf_object obj;
    auto alloc = ddwaf_get_default_allocator();
    ddwaf_object_set_array(&obj, size, alloc);
    for (int i = 0; i < size; i++) {
        auto str = std::to_string(i);
        ddwaf_object *elem = ddwaf_object_insert(&obj, alloc);
        ddwaf_object_set_string(elem, str.c_str(), str.length(), alloc);
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

TEST(ParameterTest, Bool)
{
    bool value = false;
    parameter p = parameter::as_boolean(value);
    EXPECT_EQ(p.type(), parameter_type::boolean);
    EXPECT_THROW(p[0], invalid_type);

    EXPECT_THROW(auto s = std::string(p), bad_cast);
    EXPECT_THROW(auto sv = std::string_view(p), bad_cast);
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_NO_THROW(auto boolean = bool(p));

    EXPECT_EQ(value, ddwaf_object_get_bool(&p));
}

TEST(ParameterTest, MergeArrays)
{
    parameter p1 = parameter::array();
    p1.add(parameter::string("value1"sv));

    parameter p2 = parameter::array();
    p2.add(parameter::string("value2"sv));

    bool merge_result = p1.merge(std::move(p2));

    ASSERT_TRUE(merge_result);
    ASSERT_EQ(p1.size(), 2);
    EXPECT_STREQ(std::string_view(p1[0]).data(), "value1");
    EXPECT_STREQ(std::string_view(p1[1]).data(), "value2");

    EXPECT_FALSE(p2.is_valid());
}

TEST(ParameterTest, MergeMapsWithUniqueKeys)
{
    parameter p1 = parameter::map();
    p1.add("key1", parameter::string("value1"sv));

    parameter p2 = parameter::map();
    p2.add("key2", parameter::string("value2"sv));

    bool merge_result = p1.merge(std::move(p2));

    ASSERT_TRUE(merge_result);
    ASSERT_EQ(p1.size(), 2);

    parameter_view pv{*&p1};
    auto map_it = pv.map_iterable();
    auto it = map_it.begin();
    EXPECT_STREQ((*it).first.data(), "key1");
    EXPECT_STREQ(std::string_view((*it).second).data(), "value1");
    ++it;
    EXPECT_STREQ((*it).first.data(), "key2");
    EXPECT_STREQ(std::string_view((*it).second).data(), "value2");

    EXPECT_FALSE(p2.is_valid());
}

TEST(ParameterTest, MergeMapsWithOverlappingKeys)
{
    parameter p1 = parameter::map();
    p1.add("key1", parameter::string("value1"sv));
    parameter nested1 = parameter::map();
    nested1.add("inner1", parameter::string("innerval1"sv));
    p1.add("overlap", std::move(nested1));

    parameter p2 = parameter::map();
    p2.add("key2", parameter::string("value2"sv));
    parameter nested2 = parameter::map();
    nested2.add("inner2", parameter::string("innerval2"sv));
    p2.add("overlap", std::move(nested2));

    bool merge_result = p1.merge(std::move(p2));

    ASSERT_TRUE(merge_result);
    ASSERT_EQ(p1.size(), 3); // key1, overlap, key2

    parameter_view pv{*&p1};
    auto map_it = pv.map_iterable();
    bool found_overlap = false;
    for (const auto &[key, value] : map_it) {
        if (key == "overlap") {
            found_overlap = true;
            // the overlapped entry should contain both inner keys
            ASSERT_EQ(value.type(), parameter_type::map);
            ASSERT_EQ(value.size(), 2);

            auto nested_map_it = value.map_iterable();
            bool found_inner1 = false, found_inner2 = false;
            for (const auto &[inner_key, inner_value] : nested_map_it) {
                if (inner_key == "inner1") {
                    found_inner1 = true;
                    EXPECT_STREQ(
                        std::string_view(inner_value).data(), "innerval1");
                } else if (inner_key == "inner2") {
                    found_inner2 = true;
                    EXPECT_STREQ(
                        std::string_view(inner_value).data(), "innerval2");
                }
            }
            EXPECT_TRUE(found_inner1);
            EXPECT_TRUE(found_inner2);
            break;
        }
    }
    EXPECT_TRUE(found_overlap);

    EXPECT_FALSE(p2.is_valid());
}

TEST(ParameterTest, MergeIncompatibleTypes)
{
    parameter p1 = parameter::array();
    p1.add(parameter::string("value"sv));

    parameter p2 = parameter::map();
    p2.add("key", parameter::string("value"sv));

    bool merge_result = p1.merge(std::move(p2));
    EXPECT_FALSE(merge_result);
}

TEST(ParameterTest, MergeWithInvalidParameter)
{
    parameter p1 = parameter::array();
    p1.add(parameter::string("value"sv));

    parameter p2; // invalid

    bool merge_result = p1.merge(std::move(p2));
    EXPECT_FALSE(merge_result);
}

TEST(ParameterTest, MergeNonContainerTypes)
{
    parameter p1 = parameter::string("string1"sv);
    parameter p2 = parameter::string("string2"sv);

    bool merge_result = p1.merge(std::move(p2));

    EXPECT_FALSE(merge_result);
}

} // namespace dds
