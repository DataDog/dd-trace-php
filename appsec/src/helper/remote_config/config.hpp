// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../utils.hpp"
#include "product.hpp"
#include <concepts>
#include <spdlog/spdlog.h>
#include <string>
#include <string_view>

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

// A configuration key has the form:
// (datadog/<org_id> | employee)/<PRODUCT>/<config_id>/<name>
class parsed_config_key {
public:
    template <typename Str>
        requires std::convertible_to<Str, std::string_view>
    explicit parsed_config_key(Str &&key) : key_{std::forward<Str>(key)}
    {
        parse_config_key();
    }

    parsed_config_key(const parsed_config_key &oth)
        : parsed_config_key{oth.key_}
    {}

    parsed_config_key &operator=(const parsed_config_key &oth)
    {
        if (&oth != this) {
            key_ = oth.key_;
            parse_config_key();
        }
        return *this;
    }
    parsed_config_key(parsed_config_key &&oth) noexcept
        : key_{std::move(oth.key_)}, source_{oth.source()},
          org_id_{oth.org_id_}, product_{oth.product_},
          config_id_{oth.config_id_}, name_{oth.name_}
    {
        oth.source_ = {};
        oth.org_id_ = 0;
        oth.product_ = known_products::UNKNOWN;
        oth.config_id_ = {};
        oth.name_ = {};
    }
    parsed_config_key &operator=(parsed_config_key &&oth) noexcept
    {
        if (&oth != this) {
            key_ = std::move(oth.key_);
            source_ = oth.source_;
            org_id_ = oth.org_id_;
            product_ = oth.product_;
            config_id_ = oth.config_id_;
            name_ = oth.name_;
            oth.source_ = {};
            oth.org_id_ = 0;
            oth.product_ = known_products::UNKNOWN;
            oth.config_id_ = {};
            oth.name_ = {};
        }
        return *this;
    }
    ~parsed_config_key() = default;

    bool operator==(const parsed_config_key &other) const
    {
        return key_ == other.key_;
    }

    struct hash {
        std::size_t operator()(const parsed_config_key &k) const
        {
            return std::hash<std::string>()(k.key_);
        }
    };

    // lifetime of return values is that of the data pointer in key_
    std::string_view full_key() const { return {key_}; }
    std::string_view source() const { return source_; }
    std::uint64_t org_id() const { return org_id_; }
    class product product() const { return product_; }
    std::string_view config_id() const { return config_id_; }
    std::string_view name() const { return name_; }

private:
    void parse_config_key();

    std::string key_;
    std::string_view source_;
    std::uint64_t org_id_{};
    class product product_ {
        known_products::UNKNOWN
    };
    std::string_view config_id_;
    std::string_view name_;
};

struct config {
    // from a line provided by the RC config reader
    static config from_line(std::string_view line);

    std::string shm_path;
    std::string rc_path;

    [[nodiscard]] mapped_memory read() const;

    [[nodiscard]] parsed_config_key config_key() const
    {
        return parsed_config_key{rc_path};
    }

    bool operator==(const config &b) const
    {
        return shm_path == b.shm_path && rc_path == b.rc_path;
    }
};

} // namespace dds::remote_config

template <> struct fmt::formatter<dds::remote_config::config> {
    constexpr auto parse(format_parse_context &ctx) { return ctx.begin(); }

    auto format(const dds::remote_config::config &c, format_context &ctx) const
    {
        return fmt::format_to(ctx.out(), "{}:{}", c.shm_path, c.rc_path);
    }
};

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
template <> struct hash<dds::remote_config::parsed_config_key> {
    size_t operator()(const dds::remote_config::parsed_config_key &key) const
    {
        return dds::hash(key.full_key());
    }
};
template <> struct less<dds::remote_config::parsed_config_key> {
    bool operator()(const dds::remote_config::parsed_config_key &lhs,
        const dds::remote_config::parsed_config_key &rhs) const
    {
        return lhs.full_key() < rhs.full_key();
    }
};
} // namespace std
