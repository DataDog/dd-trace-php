// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "compression.hpp"
#include <cstdint>
#include <string>
#include <zlib.h>

namespace dds {

namespace {
constexpr int64_t encoding = -0xf;
constexpr int max_round_decompression = 100;
// Taken from PHP approach
// https://heap.space/xref/PHP-7.3/ext/zlib/php_zlib.h?r=8d3f8ca1#36
size_t estimate_compressed_size(size_t in_len)
{
    // NOLINTBEGIN(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    return (((size_t)((double)in_len * (double)1.015)) + 10 + 8 + 4 + 1);
    // NOLINTEND(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
}
} // namespace

// The implementation of this function is based on how PHP does
//  https://heap.space/xref/PHP-7.3/ext/zlib/zlib.c?r=9afce019#336
std::optional<std::string> compress(const std::string &text)
{
    std::string ret_string;
    z_stream strm = {};

    if (text.length() == 0) {
        return std::nullopt;
    }

    if (Z_OK == deflateInit2(&strm, -1, Z_DEFLATED, encoding, MAX_MEM_LEVEL,
                    Z_DEFAULT_STRATEGY)) {
        auto size = estimate_compressed_size(text.length());
        ret_string.resize(size);

        // NOLINTBEGIN(cppcoreguidelines-pro-type-reinterpret-cast)
        strm.next_in = reinterpret_cast<const Bytef *>(text.data());
        strm.next_out = reinterpret_cast<Bytef *>(ret_string.data());
        // NOLINTEND(cppcoreguidelines-pro-type-reinterpret-cast)
        strm.avail_in = text.length();
        strm.avail_out = size;

        if (Z_STREAM_END == deflate(&strm, Z_FINISH)) {
            deflateEnd(&strm);
            /* size buffer down to actual length */
            ret_string.resize(strm.total_out);
            ret_string.shrink_to_fit();
            return ret_string;
        }
        deflateEnd(&strm);
    }
    return std::nullopt;
}

// Taken from PHP approach
// https://heap.space/xref/PHP-7.3/ext/zlib/zlib.c?r=9afce019#422
std::optional<std::string> uncompress(const std::string &compressed)
{
    int round = 0;
    size_t used = 0;
    size_t free;
    size_t capacity;
    z_stream strm = {};

    if (compressed.length() < 1 || Z_OK != inflateInit2(&strm, encoding)) {
        return std::nullopt;
    }

    // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
    strm.next_in = reinterpret_cast<const Bytef *>(compressed.data());
    strm.avail_in = compressed.length();
    std::string output;
    int status = Z_OK;

    capacity = strm.avail_in;
    output.resize(capacity);
    while ((Z_BUF_ERROR == status || (Z_OK == status && strm.avail_in > 0)) &&
           ++round < max_round_decompression) {
        strm.avail_out = free = capacity - used;
        // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
        strm.next_out = reinterpret_cast<uint8_t *>(output.data()) + used;
        status = inflate(&strm, Z_NO_FLUSH);
        used += free - strm.avail_out;
        capacity += (output.size() >> 3) + 1;
        output.resize(capacity);
    }
    if (status == Z_STREAM_END) {
        inflateEnd(&strm);
        output.resize(used);
        output.shrink_to_fit();
        return output;
    }
    inflateEnd(&strm);

    return std::nullopt;
}

} // namespace dds
