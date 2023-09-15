// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <exception.hpp>
#include <iostream>
#include <limits>
#include <parameter_view.hpp>

namespace dds {

TEST(ParameterViewTest, EmptyConstructor)
{
    parameter_view p;
    EXPECT_EQ(p.type(), parameter_type::invalid);
    EXPECT_FALSE(p.is_valid());

    EXPECT_THROW(auto s = std::string(p), bad_cast);
    EXPECT_THROW(auto sv = std::string_view(p), bad_cast);
    EXPECT_THROW(auto u64 = uint64_t(p), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(p), bad_cast);

    EXPECT_THROW(
        [&] {
            for (auto value : p) { EXPECT_FALSE(value.is_valid()); }
        }(),
        invalid_type);
}

TEST(ParameterViewTest, FromStringParameter)
{
    std::string value("thisisastring");
    parameter p = parameter::string(value);

    parameter_view pv(p);
    EXPECT_EQ(p.type(), pv.type());
    EXPECT_EQ(p.length(), pv.length());
    EXPECT_EQ(p.size(), pv.size());

    EXPECT_NO_THROW(auto s = std::string(pv));
    EXPECT_NO_THROW(auto sv = std::string_view(pv));
    EXPECT_THROW(auto u64 = uint64_t(pv), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(pv), bad_cast);

    EXPECT_THROW(
        [&] {
            for (auto value : pv) { EXPECT_FALSE(value.is_valid()); }
        }(),
        invalid_type);

    EXPECT_THROW(auto &pv0 = pv[0], invalid_type);
}

TEST(ParameterViewTest, FromArrayParameter)
{
    int i;
    parameter p = parameter::array();
    for (i = 0; i < 5; i++) { p.add(parameter::string(std::to_string(i))); }

    parameter_view pv(p);
    EXPECT_EQ(pv.type(), p.type());
    EXPECT_EQ(pv.length(), p.length());
    EXPECT_EQ(pv.size(), p.size());

    i = 0;
    for (auto value : pv) {
        EXPECT_TRUE(value.is_valid());
        EXPECT_TRUE(value.is_string());
        EXPECT_STREQ(std::string_view(value).data(), std::to_string(i).c_str());
        i++;
    }

    for (i = 0; i < 5; i++) {
        EXPECT_TRUE(pv[i].is_valid());
        EXPECT_TRUE(pv[i].is_string());
        EXPECT_STREQ(std::string_view(pv[i]).data(), std::to_string(i).c_str());
    }

    EXPECT_THROW(pv[i], std::out_of_range);
}

TEST(ParameterViewTest, FromMapParameter)
{
    int i;
    parameter p = parameter::map();
    for (i = 0; i < 5; i++) {
        p.add(std::to_string(i), parameter::string("value"sv));
    }

    parameter_view pv(p);
    EXPECT_EQ(pv.type(), p.type());
    EXPECT_EQ(pv.length(), p.length());
    EXPECT_EQ(pv.size(), p.size());

    i = 0;
    for (auto value : pv) {
        EXPECT_TRUE(value.is_valid());
        EXPECT_TRUE(value.is_string());
        EXPECT_STREQ(value.key().data(), std::to_string(i).c_str());
        EXPECT_STREQ(std::string_view(value).data(), "value");
        i++;
    }

    for (i = 0; i < 5; i++) {
        EXPECT_TRUE(pv[i].is_valid());
        EXPECT_TRUE(pv[i].is_string());
        EXPECT_STREQ(pv[i].key().data(), std::to_string(i).c_str());
        EXPECT_STREQ(std::string_view(pv[i]).data(), "value");
    }

    EXPECT_THROW(pv[i], std::out_of_range);
}

TEST(ParameterViewTest, FromStringObject)
{
    std::string value("thisisastring");
    ddwaf_object obj;
    ddwaf_object_stringl(&obj, value.data(), value.size());

    parameter_view pv(obj);
    EXPECT_EQ(pv.type(), parameter_type::string);
    EXPECT_EQ(pv.length(), value.size());
    EXPECT_EQ(pv.size(), 0);

    EXPECT_NO_THROW(auto s = std::string(pv));
    EXPECT_NO_THROW(auto sv = std::string_view(pv));
    EXPECT_THROW(auto u64 = uint64_t(pv), bad_cast);
    EXPECT_THROW(auto i64 = int64_t(pv), bad_cast);

    EXPECT_THROW(
        [&] {
            for (auto value : pv) { EXPECT_FALSE(value.is_valid()); }
        }(),
        invalid_type);

    EXPECT_THROW(auto &pv0 = pv[0], invalid_type);
    ddwaf_object_free(&obj);
}

TEST(ParameterViewTest, FromUint64Object)
{
    ddwaf_object obj;
    ddwaf_object_unsigned(&obj, std::numeric_limits<uint64_t>::max());

    parameter_view pv(obj);
    EXPECT_EQ(pv.type(), parameter_type::uint64);
    EXPECT_EQ(pv.length(), 0);
    EXPECT_EQ(pv.size(), 0);

    EXPECT_THROW(auto s = std::string(pv), bad_cast);
    EXPECT_THROW(auto sv = std::string_view(pv), bad_cast);
    EXPECT_NO_THROW(auto u64 = uint64_t(pv));
    EXPECT_THROW(auto i64 = int64_t(pv), bad_cast);

    EXPECT_THROW(
        [&] {
            for (auto value : pv) { EXPECT_FALSE(value.is_valid()); }
        }(),
        invalid_type);

    EXPECT_THROW(auto &pv0 = pv[0], invalid_type);
}

TEST(ParameterViewTest, FromInt64Object)
{
    ddwaf_object obj;
    ddwaf_object_signed(&obj, std::numeric_limits<int64_t>::min());

    parameter_view pv(obj);
    EXPECT_EQ(pv.type(), parameter_type::int64);
    EXPECT_EQ(pv.length(), 0);
    EXPECT_EQ(pv.size(), 0);

    EXPECT_THROW(auto s = std::string(pv), bad_cast);
    EXPECT_THROW(auto sv = std::string_view(pv), bad_cast);
    EXPECT_THROW(auto u64 = uint64_t(pv), bad_cast);
    EXPECT_NO_THROW(auto i64 = int64_t(pv));

    EXPECT_THROW(
        [&] {
            for (auto value : pv) { EXPECT_FALSE(value.is_valid()); }
        }(),
        invalid_type);

    EXPECT_THROW(auto &pv0 = pv[0], invalid_type);
}

TEST(ParameterViewTest, FromArrayObject)
{
    int i;
    int size = 40;
    ddwaf_object obj, tmp;
    ddwaf_object_array(&obj);
    for (i = 0; i < size; i++) {
        ddwaf_object_array_add(
            &obj, ddwaf_object_string(&tmp, std::to_string(i).c_str()));
    }

    parameter_view pv(obj);
    EXPECT_EQ(pv.type(), parameter_type::array);
    EXPECT_EQ(pv.length(), 0);
    EXPECT_EQ(pv.size(), size);

    i = 0;
    for (auto value : pv) {
        EXPECT_TRUE(value.is_valid());
        EXPECT_TRUE(value.is_string());
        EXPECT_STREQ(std::string_view(value).data(), std::to_string(i).c_str());
        i++;
    }

    for (i = 0; i < size; i++) {
        EXPECT_TRUE(pv[i].is_valid());
        EXPECT_TRUE(pv[i].is_string());
        EXPECT_STREQ(std::string_view(pv[i]).data(), std::to_string(i).c_str());
    }

    EXPECT_THROW(pv[i], std::out_of_range);

    ddwaf_object_free(&obj);
}

TEST(ParameterViewTest, FromMapObject)
{
    int i;
    int size = 40;
    ddwaf_object obj, tmp;
    ddwaf_object_map(&obj);
    for (i = 0; i < size; i++) {
        ddwaf_object_map_add(&obj, std::to_string(i).c_str(),
            ddwaf_object_string(&tmp, "value"));
    }

    parameter_view pv(obj);
    EXPECT_EQ(pv.type(), parameter_type::map);
    EXPECT_EQ(pv.length(), 0);
    EXPECT_EQ(pv.size(), size);

    i = 0;
    for (auto value : pv) {
        EXPECT_TRUE(value.is_valid());
        EXPECT_TRUE(value.is_string());
        EXPECT_STREQ(value.key().data(), std::to_string(i).c_str());
        EXPECT_STREQ(std::string_view(value).data(), "value");
        i++;
    }

    for (i = 0; i < size; i++) {
        EXPECT_TRUE(pv[i].is_valid());
        EXPECT_TRUE(pv[i].is_string());
        EXPECT_STREQ(pv[i].key().data(), std::to_string(i).c_str());
        EXPECT_STREQ(std::string_view(pv[i]).data(), "value");
    }

    EXPECT_THROW(pv[i], std::out_of_range);

    ddwaf_object_free(&obj);
}

TEST(ParameterViewTest, StaticCastFromMapObject)
{
    int i;
    int size = 40;
    ddwaf_object obj, tmp;
    ddwaf_object_map(&obj);
    for (i = 0; i < size; i++) {
        ddwaf_object_map_add(&obj, std::to_string(i).c_str(),
            ddwaf_object_string(&tmp, "value"));
    }

    parameter_view pv = static_cast<parameter_view>(obj);
    ;
    EXPECT_EQ(pv.type(), parameter_type::map);
    EXPECT_EQ(pv.length(), 0);
    EXPECT_EQ(pv.size(), size);

    i = 0;
    for (auto value : pv) {
        EXPECT_TRUE(value.is_valid());
        EXPECT_TRUE(value.is_string());
        EXPECT_STREQ(value.key().data(), std::to_string(i).c_str());
        EXPECT_STREQ(std::string_view(value).data(), "value");
        i++;
    }

    for (i = 0; i < size; i++) {
        EXPECT_TRUE(pv[i].is_valid());
        EXPECT_TRUE(pv[i].is_string());
        EXPECT_STREQ(pv[i].key().data(), std::to_string(i).c_str());
        EXPECT_STREQ(std::string_view(pv[i]).data(), "value");
    }

    EXPECT_THROW(pv[i], std::out_of_range);

    ddwaf_object_free(&obj);
}

TEST(ParameterViewTest, StaticCastFromArrayObject)
{
    int i;
    int size = 40;
    ddwaf_object obj, tmp;
    ddwaf_object_array(&obj);
    for (i = 0; i < size; i++) {
        ddwaf_object_array_add(
            &obj, ddwaf_object_string(&tmp, std::to_string(i).c_str()));
    }

    parameter_view pv = static_cast<parameter_view>(obj);
    EXPECT_EQ(pv.type(), parameter_type::array);
    EXPECT_EQ(pv.length(), 0);
    EXPECT_EQ(pv.size(), size);

    i = 0;
    for (auto value : pv) {
        EXPECT_TRUE(value.is_valid());
        EXPECT_TRUE(value.is_string());
        EXPECT_STREQ(std::string_view(value).data(), std::to_string(i).c_str());
        i++;
    }

    for (i = 0; i < size; i++) {
        EXPECT_TRUE(pv[i].is_valid());
        EXPECT_TRUE(pv[i].is_string());
        EXPECT_STREQ(std::string_view(pv[i]).data(), std::to_string(i).c_str());
    }

    EXPECT_THROW(pv[size], std::out_of_range);

    ddwaf_object_free(&obj);
}

} // namespace dds
