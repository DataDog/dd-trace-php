// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <result.hpp>

namespace dds {

TEST(ResultTest, OkResult)
{
    result res{result::code::ok};
    EXPECT_EQ(res.value, result::code::ok);
    EXPECT_LT(res.value, result::code::record);
    EXPECT_LT(res.value, result::code::block);
    EXPECT_EQ(res.data.size(), 0);
}

TEST(ResultTest, RecordResult)
{
    result res{result::code::record};
    EXPECT_EQ(res.value, result::code::record);
    EXPECT_GT(res.value, result::code::ok);
    EXPECT_LT(res.value, result::code::block);
    EXPECT_EQ(res.data.size(), 0);
}

TEST(ResultTest, BlockResult)
{
    result res{result::code::block};
    EXPECT_EQ(res.value, result::code::block);
    EXPECT_GT(res.value, result::code::ok);
    EXPECT_GT(res.value, result::code::record);
    EXPECT_EQ(res.data.size(), 0);
}

TEST(ResultTest, ResultWithData)
{
    result res{result::code::block, {"this was a result"}};
    EXPECT_EQ(res.value, result::code::block);
    EXPECT_TRUE(res.data[0] == "this was a result");
}

TEST(ResultTest, ResultMoveConstructor)
{
    result res{result::code::block, {"this was a moved result"}};
    result new_res = std::move(res);

    EXPECT_EQ(new_res.value, result::code::block);
    EXPECT_TRUE(new_res.data[0] == "this was a moved result");
}

TEST(ResultTest, ResultMoveAssignment)
{
    result res{result::code::block, {"this was a moved result"}};
    result new_res{result::code::ok};

    new_res = std::move(res);
    EXPECT_EQ(new_res.value, result::code::block);
    EXPECT_TRUE(new_res.data[0] == "this was a moved result");
}

} // namespace dds
