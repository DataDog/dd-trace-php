// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#pragma once

#include "utils.hpp"
#include <algorithm>
#include <cstdint>
#include <msgpack.hpp>
#include <ostream>
#include <string>

namespace dds::remote_config {

/* client_settings are currently the same for the whole client session.
 * If this changes in the future, it will make sense to create a separation
 * between 1) settings used for creating the engine and 2) settings used
 * after, possibly when creating the subscriber listeners on every request
 */
struct settings {
    static constexpr uint32_t default_poll_interval{1000};
    static constexpr uint64_t default_max_payload_size{4096};
    static constexpr unsigned default_port{8126};
    // Remote config settings
    bool enabled{false};
    std::string host;
    unsigned port = default_port;
    std::uint32_t poll_interval = default_poll_interval;
    std::uint64_t max_payload_size = default_max_payload_size;

    // these two are specified in RCTE1
    // std::string targets_key;
    // std::string targets_key_id;
    // bool integrity_check_enabled{false};

    MSGPACK_DEFINE_MAP(enabled, host, port, poll_interval, max_payload_size);

    bool operator==(const settings &oth) const noexcept
    {
        return enabled == oth.enabled && host == oth.host && port == oth.port &&
               poll_interval == oth.poll_interval &&
               max_payload_size == oth.max_payload_size;
    }

    friend auto &operator<<(std::ostream &os, const settings &c)
    {
        return os << "{enabled=" << std::boolalpha << c.enabled
                  << ", host=" << c.host << ", port=" << c.port
                  << ", poll_interval=" << c.poll_interval
                  << ", max_payload_size=" << c.max_payload_size << "}";
    }

    struct settings_hash {
        std::size_t operator()(const settings &s) const noexcept
        {
            return hash(
                s.enabled, s.host, s.port, s.poll_interval, s.max_payload_size);
        }
    };
};
} // namespace dds::remote_config
