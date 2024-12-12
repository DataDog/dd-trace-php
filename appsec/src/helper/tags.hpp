// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include <string_view>

namespace dds::tag {

constexpr std::string_view event_rules_loaded = "_dd.appsec.event_rules.loaded";
constexpr std::string_view event_rules_failed =
    "_dd.appsec.event_rules.error_count";
constexpr std::string_view event_rules_errors = "_dd.appsec.event_rules.errors";
constexpr std::string_view event_rules_version =
    "_dd.appsec.event_rules.version";

constexpr std::string_view waf_version = "_dd.appsec.waf.version";
constexpr std::string_view waf_duration = "_dd.appsec.waf.duration";

} // namespace dds::tag
