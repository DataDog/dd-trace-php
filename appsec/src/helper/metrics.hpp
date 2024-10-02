// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include <functional>
#include <string>
#include <string_view>

namespace dds::metrics {

struct TelemetrySubmitter {
    TelemetrySubmitter() = default;
    TelemetrySubmitter(const TelemetrySubmitter &) = delete;
    TelemetrySubmitter &operator=(const TelemetrySubmitter &) = delete;
    TelemetrySubmitter(TelemetrySubmitter &&) = delete;
    TelemetrySubmitter &operator=(TelemetrySubmitter &&) = delete;

    virtual ~TelemetrySubmitter() = 0;
    virtual void submit_metric(std::string_view, double, std::string) = 0;
    virtual void submit_legacy_metric(std::string_view, double) = 0;
    virtual void submit_legacy_meta(std::string_view, std::string) = 0;
    virtual void submit_legacy_meta_copy_key(std::string, std::string) = 0;
};

constexpr std::string_view waf_init = "waf.init";
constexpr std::string_view waf_updates = "waf.updates";
constexpr std::string_view waf_requests = "waf.requests";

// not implemented:
constexpr std::string_view waf_input_truncated = "waf.input_truncated";

// legacy
constexpr std::string_view event_rules_loaded = "_dd.appsec.event_rules.loaded";
constexpr std::string_view event_rules_failed =
    "_dd.appsec.event_rules.error_count";
constexpr std::string_view event_rules_errors = "_dd.appsec.event_rules.errors";
constexpr std::string_view event_rules_version =
    "_dd.appsec.event_rules.version";

constexpr std::string_view waf_version = "_dd.appsec.waf.version";
constexpr std::string_view waf_duration = "_dd.appsec.waf.duration";

} // namespace dds::metrics
