// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include "config.hpp"

namespace dds::config {

config::config(int argc, char *argv[]) { // NOLINT
    for (int i = 1; i < argc; ++i) {
        std::string_view arg(argv[i]);
        if (arg.size() < 2 || arg.substr(0, 2) != "--") {
            // Not an option, weird
            continue;
        }
        arg.remove_prefix(2);

        // Check if the option has an assignment
        auto pos = arg.find('=');
        if (pos != std::string::npos) {
            kv_[arg.substr(0, pos)] = arg.substr(pos + 1);
            continue;
        }

        // Check the next argument
        if ((i + 1) < argc) {
            std::string_view value(argv[i + 1]);
            if (arg.size() < 2 || arg.substr(0, 2) != "--") {
                // Not an option, so we assume it's a value
                kv_[arg] = value;
                // Skip on next iteration
                ++i;
                continue;
            }
        }

        // If the next argument is an option or this is the last argument, we
        // assume it's just a modifier.
        kv_[arg] = std::string_view();
    }
}

template <> bool config::get<bool>(std::string_view key) const {
    return kv_.find(key) != kv_.end();
}

template <> std::string config::get<std::string>(std::string_view key) const {
    return std::string(kv_.at(key));
}

template <>
std::string_view config::get<std::string_view>(std::string_view key) const {
    return kv_.at(key);
}

// NOLINTNEXTLINE
const std::unordered_map<std::string_view, std::string_view> config::defaults =
    {
        {"lock_path", "/tmp/ddappsec.lock"},
        {"socket_path", "/tmp/ddappsec.sock"},
        {"log_level", "info"},
};

} // namespace dds::config
