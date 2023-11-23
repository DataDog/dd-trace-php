// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <compression.hpp>
#include <optional>

namespace dds {

TEST(CompressionTest, Compress)
{
    {
        std::string text = "Some string to compress";
        std::optional<std::string> compressed = compress(text);

        auto uncompressed = uncompress(compressed.value());

        EXPECT_STREQ(text.c_str(), uncompressed->c_str());
    }
    {
        std::string text =
            "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer "
            "mattis libero quis velit tempus ultrices. Sed eget tortor lectus. "
            "Fusce posuere luctus luctus. Vestibulum ante ipsum primis in "
            "faucibus orci luctus et ultrices posuere cubilia curae; Quisque "
            "dui tortor, dictum consequat posuere at, tempus non nunc. Sed "
            "libero dolor, bibendum sed lacus quis, viverra fringilla felis. "
            "Integer rutrum hendrerit venenatis. Ut aliquam nisi vitae libero "
            "blandit venenatis. Suspendisse viverra id mauris id placerat. "
            "Mauris sodales venenatis rhoncus. Maecenas finibus venenatis leo, "
            "ut convallis urna faucibus et. Mauris a euismod libero, nec "
            "pellentesque neque. Nunc pretium a mi et malesuada. Donec eget "
            "leo semper, mollis velit non, elementum felis. Vivamus eu varius "
            "ipsum.\n"
            "\n"
            "In consectetur nisi sit amet turpis lobortis euismod. Vestibulum "
            "iaculis dui id ipsum consectetur, quis facilisis sem tristique. "
            "Quisque non velit porttitor, luctus ex at, lobortis diam. "
            "Curabitur eu metus maximus, vehicula turpis at, suscipit sapien. "
            "Duis nibh purus, gravida vitae cursus at, placerat vel erat. "
            "Etiam malesuada purus non pellentesque aliquam. Maecenas egestas "
            "suscipit eros sit amet fermentum. Vivamus ullamcorper molestie "
            "tempus. Maecenas semper accumsan vulputate. Praesent cursus ante "
            "risus, sed tincidunt nibh ullamcorper ultricies. Suspendisse "
            "potenti.";
        std::optional<std::string> compressed = compress(text);

        auto uncompressed = uncompress(compressed.value());

        EXPECT_STREQ(text.c_str(), uncompressed->c_str());
    }
    {
        std::string text = "";
        std::optional<std::string> compressed = compress(text);

        EXPECT_FALSE(compressed.has_value());
    }
}

TEST(CompressionTest, Uncompressed)
{
    {
        std::string text = "";
        std::optional<std::string> compressed = uncompress(text);

        EXPECT_FALSE(compressed.has_value());
    }
    {
        std::string text = "something not compressed";
        std::optional<std::string> compressed = uncompress(text);

        EXPECT_FALSE(compressed.has_value());
    }
}

} // namespace dds
