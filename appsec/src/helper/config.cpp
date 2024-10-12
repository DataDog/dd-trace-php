// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "config.hpp"

namespace dds::config {

// NOLINTNEXTLINE
config::config(
    const std::function<std::optional<std::string_view>(std::string_view)> &fn)
{
    auto get_env = [&fn](std::string_view key) -> std::string_view {
        return fn(key).value_or(defaults.at(key));
    };
    kv_[env_socket_file_path] = get_env(env_socket_file_path);
    kv_[env_lock_file_path] = get_env(env_lock_file_path);
    kv_[env_log_file_path] = get_env(env_log_file_path);
    kv_[env_log_level] = get_env(env_log_level);
}

// NOLINTNEXTLINE
const std::unordered_map<std::string_view, std::string_view> config::defaults =
    {
        {env_lock_file_path, "/tmp/ddappsec.lock"},
        {env_socket_file_path, "/tmp/ddappsec.sock"},
        {env_log_file_path, "/tmp/ddappsec_helper.log"},
        {env_log_level, "warn"},
};

} // namespace dds::config
