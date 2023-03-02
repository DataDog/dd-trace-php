// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#pragma once

#include "../common.hpp"
#include "base64.h"
#include "engine.hpp"
#include "remote_config/config.hpp"

namespace dds::remote_config::mock {

class engine : public dds::engine {
public:
    explicit engine(
        uint32_t trace_rate_limit = engine_settings::default_trace_rate_limit,
        action_map &&actions = {})
        : dds::engine(trace_rate_limit, std::move(actions))
    {}
    MOCK_METHOD(void, update,
        (engine_ruleset &, (std::map<std::string_view, std::string> &),
            (std::map<std::string_view, double> &)),
        (override));

    static auto create() { return std::shared_ptr<engine>(new engine()); }
};

inline remote_config::config generate_config(
    const std::string &content, bool encode = true)
{
    std::string encoded_content = content;
    if (encode) {
        encoded_content = base64_encode(content);
    }

    return {"product", "id", encoded_content, "path", {}, 123, 321,
        remote_config::protocol::config_state::applied_state::UNACKNOWLEDGED,
        ""};
}

} // namespace dds::remote_config::mock
