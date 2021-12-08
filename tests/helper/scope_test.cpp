// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <scope.hpp>

namespace dds {

TEST(ScopeTest, BasicTest)
{
    ref_counted r;
    EXPECT_EQ(r.reference_count(), 0);

    {
        scope<ref_counted> s(r);
        EXPECT_EQ(r.reference_count(), 1);

        {
            scope<ref_counted> s2(r);
            EXPECT_EQ(r.reference_count(), 2);
        }

        EXPECT_EQ(r.reference_count(), 1);
    }

    EXPECT_EQ(r.reference_count(), 0);
}

} // namespace dds
