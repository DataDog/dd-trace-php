// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../utils.hpp"
#include "product.hpp"
#include <string>
#include <string_view>
#include <vector>

extern "C" {
#include <sys/mman.h>
}

namespace dds::remote_config {

class mapped_memory {
public:
    mapped_memory(void *ptr, std::size_t size) : ptr_{ptr}, size_{size} {}
    mapped_memory(const mapped_memory &) = delete;
    mapped_memory(mapped_memory &&mm) noexcept : ptr_{mm.ptr_}, size_{mm.size_}
    {
        mm.ptr_ = nullptr;
        mm.size_ = 0;
    }
    mapped_memory &operator=(const mapped_memory &) = delete;
    mapped_memory &operator=(mapped_memory &&mm) noexcept
    {
        ptr_ = mm.ptr_;
        size_ = mm.size_;
        mm.ptr_ = nullptr;
        mm.size_ = 0;
        return *this;
    }
    ~mapped_memory() noexcept
    {
        if (ptr_ != nullptr) {
            if (::munmap(ptr_, size_) == -1) {
                SPDLOG_WARN(
                    "Failed to unmap shared memory: {}", strerror_ts(errno));
            };
        }
    }

    operator std::string_view() const // NOLINT
    {
        return std::string_view{static_cast<char *>(ptr_), size_};
    }

private:
    void *ptr_;
    std::size_t size_;
};

struct config {
    // from a line provided by the RC config reader
    static config from_line(std::string_view line);

    std::string shm_path;
    std::string rc_path;

    [[nodiscard]] mapped_memory read() const;

    [[nodiscard]] product get_product() const;

    bool operator==(const config &b) const
    {
        return shm_path == b.shm_path && rc_path == b.rc_path;
    }

    friend std::ostream &operator<<(std::ostream &os, const config &c)
    {
        return os << c.shm_path << ":" << c.rc_path;
    }
};

} // namespace dds::remote_config

namespace std {
template <> struct hash<dds::remote_config::config> {
    std::size_t operator()(const dds::remote_config::config &key) const
    {
        return dds::hash(key.shm_path, key.rc_path);
    }
};
template <> struct less<dds::remote_config::config> {
    bool operator()(const dds::remote_config::config &lhs,
        const dds::remote_config::config &rhs) const
    {
        if (lhs.rc_path < rhs.rc_path) {
            return true;
        };
        if (lhs.rc_path > rhs.rc_path) {
            return false;
        }
        return lhs.shm_path < rhs.shm_path;
    }
};
} // namespace std
