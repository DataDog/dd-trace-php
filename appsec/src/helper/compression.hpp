// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <optional>
#include <string>

namespace dds {

std::optional<std::string> compress(const std::string &text);
std::optional<std::string> uncompress(const std::string &compressed);

} // namespace dds
