// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <cinttypes>
#include <exception.hpp>
#include <json_helper.hpp>
#include <parameter_view.hpp>

#define STR_HELPER(x) #x
#define STR(x) STR_HELPER(x)

namespace dds {

TEST(JsonHelperTest, Int64ToJson)
{
    ddwaf_object obj;
    ddwaf_object_signed_force(&obj, std::numeric_limits<int64_t>::max());

    parameter_view pv(obj);
    std::string result = parameter_to_json(pv);

    EXPECT_EQ(std::to_string(std::numeric_limits<int64_t>::max()), result);
}

TEST(JsonHelperTest, Uint64ToJson)
{
    ddwaf_object obj;
    ddwaf_object_unsigned_force(&obj, std::numeric_limits<uint64_t>::max());

    parameter_view pv(obj);
    std::string result = parameter_to_json(pv);

    EXPECT_EQ(std::to_string(std::numeric_limits<uint64_t>::max()), result);
}

TEST(JsonHelperTest, InvalidTypeToJson)
{
    ddwaf_object obj;
    ddwaf_object_invalid(&obj);
    std::string result;

    parameter_view pv(obj);
    EXPECT_NO_THROW(result = parameter_to_json(pv));

    EXPECT_EQ("", result);
}

} // namespace dds
