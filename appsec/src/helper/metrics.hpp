// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include <spdlog/spdlog.h>
#include <string_view>

namespace dds::metrics {

// telemetry
constexpr std::string_view waf_init = "waf.init";
constexpr std::string_view waf_updates = "waf.updates";
constexpr std::string_view waf_requests = "waf.requests";
constexpr std::string_view waf_config_errors = "waf.config_errors";

// not implemented:
constexpr std::string_view waf_input_truncated = "waf.input_truncated";
constexpr std::string_view waf_truncated_value_size =
    "waf.truncated_value_size";
constexpr std::string_view waf_duration_tel = "waf.duration";
constexpr std::string_view waf_duration_ext = "waf.duration_ext";

// not implemented (difficult to count requests on the helper)
constexpr std::string_view rc_requests_before_running =
    "remote_config.requests_before_running";

// legacy
constexpr std::string_view event_rules_loaded = "_dd.appsec.event_rules.loaded";
constexpr std::string_view event_rules_failed =
    "_dd.appsec.event_rules.error_count";
constexpr std::string_view event_rules_errors = "_dd.appsec.event_rules.errors";
constexpr std::string_view event_rules_version =
    "_dd.appsec.event_rules.version";

constexpr std::string_view waf_version = "_dd.appsec.waf.version";
constexpr std::string_view waf_duration = "_dd.appsec.waf.duration";

// rasp
constexpr std::string_view rasp_duration = "_dd.appsec.rasp.duration";
constexpr std::string_view rasp_rule_eval = "_dd.appsec.rasp.rule.eval";
constexpr std::string_view rasp_timeout = "_dd.appsec.rasp.timeout";
constexpr std::string_view telemetry_rasp_rule_eval = "rasp.rule.eval";
constexpr std::string_view telemetry_rasp_error = "rasp.error";
constexpr std::string_view telemetry_rasp_rule_match = "rasp.rule.match";
constexpr std::string_view telemetry_rasp_timeout = "rasp.timeout";

} // namespace dds::metrics
