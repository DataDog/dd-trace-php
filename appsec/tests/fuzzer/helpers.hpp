// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <algorithm>
#include <cstdint>
#include <cstring>
#include <limits>
#include <utility>

namespace dds::fuzzer {

class reader {
public:
    reader() = default;
    reader(uint8_t *bytes_, size_t size_)
        : start(bytes_), cursor(bytes_), end(start + size_)
    {}

    size_t remaining_bytes() { return end - cursor; }

    std::size_t read_bytes(uint8_t *buffer, size_t size)
    {
        if (cursor >= end) {
            return 0;
        }
        size_t available = std::min(static_cast<size_t>(end - cursor), size);
        if (buffer != nullptr) {
            memcpy(buffer, cursor, available);
        }
        cursor += available;
        return available;
    }

    template <typename T> bool read(T &value)
    {
        if (sizeof(T) > remaining_bytes()) {
            return false;
        }
        memcpy(&value, cursor, sizeof(T));
        cursor += sizeof(T);
        return true;
    }

    const uint8_t *get_cursor() { return cursor; }

protected:
    const uint8_t *start{nullptr};
    const uint8_t *cursor{nullptr};
    const uint8_t *end{nullptr};
};

class writer {
public:
    explicit writer(size_t size)
        : start(new uint8_t[size]()), cursor(start), end(start + size)
    {}

    ~writer() { delete[] start; }

    size_t remaining_bytes() { return end - cursor; }
    size_t written_bytes() { return cursor - start; }

    void copy_to(uint8_t *buffer, size_t max_len)
    {
        memcpy(
            buffer, start, std::min(static_cast<size_t>(end - start), max_len));
    }

    std::pair<uint8_t *, size_t> write(const uint8_t *buffer, size_t len)
    {
        if (cursor + len > end) {
            len = end - cursor;
            return std::make_pair(end, 0);
        }
        memcpy(cursor, buffer, len);

        auto *current = cursor;
        cursor += len;
        return std::make_pair(current, len);
    }

    template <typename T> std::pair<uint8_t *, size_t> write(T *value)
    {
        return write(reinterpret_cast<uint8_t *>(value), sizeof(T));
    }

    uint8_t *get_cursor() { return cursor; }
    uint8_t *get_start() { return start; }

protected:
    uint8_t *start{nullptr};
    uint8_t *cursor{nullptr};
    uint8_t *end{nullptr};
};

} // namespace dds::fuzzer
