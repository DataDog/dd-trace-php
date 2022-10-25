// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <result.hpp>

namespace dds {

TEST(ResultTest, Invalid)
{
    result res;
    EXPECT_FALSE(res.valid());
}

TEST(ResultTest, Constructor)
{
    result res{{"this was a result"}, {"block", "record"}};
    EXPECT_TRUE(res.valid());
    EXPECT_STREQ(res.data[0].c_str(), "this was a result");
    EXPECT_NE(res.actions.find("block"), res.actions.end());
    EXPECT_NE(res.actions.find("record"), res.actions.end());
}

TEST(ResultTest, MoveConstructor)
{
    result res{{"this was a moved result"}, {"block", "record"}};
    result new_res = std::move(res);

    EXPECT_TRUE(new_res.valid());
    EXPECT_STREQ(new_res.data[0].c_str(), "this was a moved result");
    EXPECT_NE(new_res.actions.find("block"), new_res.actions.end());
    EXPECT_NE(new_res.actions.find("record"), new_res.actions.end());
}

TEST(ResultTest, MoveAssignment)
{
    result res{{"this was a moved result"}, {"block", "record"}};
    result new_res{{}, {}};

    new_res = std::move(res);
    EXPECT_TRUE(new_res.valid());
    EXPECT_STREQ(new_res.data[0].c_str(), "this was a moved result");
    EXPECT_NE(new_res.actions.find("block"), new_res.actions.end());
    EXPECT_NE(new_res.actions.find("record"), new_res.actions.end());
}

} // namespace dds
