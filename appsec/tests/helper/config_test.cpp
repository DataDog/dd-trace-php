// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include <config.hpp>
#include <optional>
#include <spdlog/common.h>

namespace dds {

namespace {
template <typename T, size_t size> constexpr size_t vsize(T (&)[size])
{
    return size;
}
} // namespace

TEST(ConfigTest, TestDefaultKeys)
{
    config::config cfg{[](std::string_view) { return std::nullopt; }};
    EXPECT_EQ(cfg.lock_file_path(), "/tmp/ddappsec.lock"sv);
    EXPECT_EQ(cfg.socket_file_path(), "/tmp/ddappsec.sock"sv);
    EXPECT_EQ(cfg.log_file_path(), "/tmp/ddappsec_helper.log"sv);
    EXPECT_EQ(cfg.log_level(), spdlog::level::level_enum::warn);
}

TEST(ConfigTest, TestDefaultOverride)
{
    static std::unordered_map<std::string_view, std::string_view> defaults = {
        {"_DD_SIDECAR_APPSEC_LOCK_FILE_PATH", "/foo/ddappsec.lock"},
        {"_DD_SIDECAR_APPSEC_SOCKET_FILE_PATH", "/foo/ddappsec.sock"},
        {"_DD_SIDECAR_APPSEC_LOG_FILE_PATH", "/foo/ddappsec_helper.log"},
        {"_DD_SIDECAR_APPSEC_LOG_LEVEL", "debug"}};

    config::config cfg{[&](std::string_view key) {
        auto it = defaults.find(key);
        if (it != defaults.end()) {
            return std::optional<std::string_view>{it->second};
        }
        return std::optional<std::string_view>{};
    }};

    EXPECT_EQ(cfg.lock_file_path(), "/foo/ddappsec.lock"sv);
    EXPECT_EQ(cfg.log_file_path(), "/foo/ddappsec_helper.log"sv);
    EXPECT_EQ(cfg.socket_file_path(), "/foo/ddappsec.sock"sv);
    EXPECT_EQ(cfg.log_level(), spdlog::level::level_enum::debug);
}

} // namespace dds
