// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <cstdint>
#include <string>
#include <vector>

#include "client_state.hpp"
#include "client_tracer.hpp"

namespace dds::remote_config::protocol {

enum class capabilities_e : uint32_t {
    NONE = 0,
    RESERVED = 1,
    ASM_ACTIVATION = 1 << 1,
    ASM_IP_BLOCKING = 1 << 2,
    ASM_DD_RULES = 1 << 3,
    ASM_EXCLUSIONS = 1 << 4,
    ASM_REQUEST_BLOCKING = 1 << 5,
    ASM_RESPONSE_BLOCKING = 1 << 6,
    ASM_USER_BLOCKING = 1 << 7,
    ASM_CUSTOM_RULES = 1 << 8,
    ASM_CUSTOM_BLOCKING_RESPONSE = 1 << 9,
    ASM_TRUSTED_IPS = 1 << 10,
    ASM_RASP_LFI = 1 << 22,
};

constexpr capabilities_e operator|(
    const capabilities_e &lhs, capabilities_e rhs)
{
    return static_cast<capabilities_e>(
        static_cast<std::underlying_type<capabilities_e>::type>(lhs) |
        static_cast<std::underlying_type<capabilities_e>::type>(rhs));
}

constexpr capabilities_e &operator|=(
    capabilities_e &lhs, const capabilities_e rhs)
{
    lhs = lhs | rhs;
    return lhs;
}

constexpr capabilities_e operator&(capabilities_e lhs, capabilities_e rhs)
{
    return static_cast<capabilities_e>(
        static_cast<std::underlying_type<capabilities_e>::type>(lhs) &
        static_cast<std::underlying_type<capabilities_e>::type>(rhs));
}

struct client {
    std::string id;
    std::vector<std::string> products;
    protocol::client_tracer client_tracer;
    protocol::client_state client_state;
    capabilities_e capabilities;
};

inline bool operator==(const client &rhs, const client &lhs)
{
    return rhs.id == lhs.id && rhs.products == lhs.products &&
           rhs.client_tracer == lhs.client_tracer &&
           rhs.client_state == lhs.client_state &&
           rhs.capabilities == lhs.capabilities;
}

} // namespace dds::remote_config::protocol
