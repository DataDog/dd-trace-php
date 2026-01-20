// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <optional>
#include <spdlog/spdlog.h>
#include <string_view>
#include <unordered_map>

namespace dds::config {
// TODO: Rename to ArgConfig or ArgParser?
//       Perhaps make this a "singleton"
class config {
public:
    explicit config(
        const std::function<std::optional<std::string_view>(std::string_view)>
            &fn);

    [[nodiscard]] std::string_view socket_file_path() const
    {
        return kv_.at(env_socket_file_path);
    }

    [[nodiscard]] bool is_abstract_socket() const
    {
        std::string_view const sfp = socket_file_path();
        if (sfp.empty()) {
            return false;
        }
        return sfp[0] == '@';
    }

    [[nodiscard]] std::string_view lock_file_path() const
    {
        return kv_.at(env_lock_file_path);
    }

    [[nodiscard]] std::string_view log_file_path() const
    {
        return kv_.at(env_log_file_path);
    }

    [[nodiscard]] spdlog::level::level_enum log_level() const
    {
        return spdlog::level::from_str(std::string{kv_.at(env_log_level)});
    }

protected:
    static const std::unordered_map<std::string_view, std::string_view>
        defaults;
    std::unordered_map<std::string_view, std::string_view> kv_{defaults};

    static constexpr std::string_view env_socket_file_path =
        "_DD_SIDECAR_APPSEC_SOCKET_FILE_PATH";
    static constexpr std::string_view env_lock_file_path =
        "_DD_SIDECAR_APPSEC_LOCK_FILE_PATH";
    static constexpr std::string_view env_log_file_path =
        "_DD_SIDECAR_APPSEC_LOG_FILE_PATH";
    static constexpr std::string_view env_log_level =
        "_DD_SIDECAR_APPSEC_LOG_LEVEL";
};

} // namespace dds::config
