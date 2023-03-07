// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <optional>
#include <vector>

#include "../cached_target_files.hpp"
#include "../client.hpp"
#include "../target_file.hpp"
#include "../targets.hpp"

namespace dds::remote_config::protocol {

struct info_response {
    std::vector<std::string> endpoints;
};

} // namespace dds::remote_config::protocol
