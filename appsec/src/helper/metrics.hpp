// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include <spdlog/spdlog.h>
#include <string>
#include <string_view>

namespace dds::metrics {

class telemetry_tags {
public:
    telemetry_tags &add(std::string_view key, std::string_view value)
    {
        data_.reserve(data_.size() + key.size() + value.size() + 2);
        if (!data_.empty()) {
            data_ += ',';
        }
        data_ += key;
        data_ += ':';
        data_ += value;
        return *this;
    }
    std::string consume() { return std::move(data_); }

    // the rest of the methods are for testing
    static telemetry_tags from_string(std::string str)
    {
        telemetry_tags tags;
        tags.data_ = std::move(str);
        return tags;
    }

    bool operator==(const telemetry_tags &other) const
    {
        return data_ == other.data_;
    }

    friend std::ostream &operator<<(
        std::ostream &os, const telemetry_tags &tags)
    {
        os << tags.data_;
        return os;
    }

private:
    std::string data_;

    friend struct fmt::formatter<telemetry_tags>;
};

struct telemetry_submitter {
    telemetry_submitter() = default;
    telemetry_submitter(const telemetry_submitter &) = delete;
    telemetry_submitter &operator=(const telemetry_submitter &) = delete;
    telemetry_submitter(telemetry_submitter &&) = delete;
    telemetry_submitter &operator=(telemetry_submitter &&) = delete;

    virtual ~telemetry_submitter() = 0;
    // first arguments of type string_view should have static storage
    virtual void submit_metric(std::string_view, double, telemetry_tags) = 0;
    virtual void submit_span_metric(std::string_view, double) = 0;
    virtual void submit_span_meta(std::string_view, std::string) = 0;
    void submit_span_meta(std::string, std::string) = delete;
    virtual void submit_span_meta_copy_key(std::string, std::string) = 0;
    void submit_span_meta_copy_key(std::string_view, std::string) = delete;
};
inline telemetry_submitter::~telemetry_submitter() = default;

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
constexpr std::string_view telemetry_rasp_rule_match = "rasp.rule.match";
constexpr std::string_view telemetry_rasp_timeout = "rasp.timeout";

} // namespace dds::metrics

template <>
struct fmt::formatter<dds::metrics::telemetry_tags>
    : fmt::formatter<std::string_view> {

    auto format(
        const dds::metrics::telemetry_tags tags, format_context &ctx) const
    {
        return formatter<std::string_view>::format(
            std::string_view{tags.data_}, ctx);
    }
};
