// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../engine.hpp"
#include "../engine_ruleset.hpp"
#include "../exception.hpp"
#include "../metrics.hpp"
#include "../parameter.hpp"
#include <chrono>
#include <ddwaf.h>
#include <spdlog/spdlog.h>
#include <string>
#include <string_view>

namespace dds::waf {

void initialise_logging(spdlog::level::level_enum level);

class instance : public dds::subscriber {
public:
    static constexpr int default_waf_timeout_us = 10000;
    static constexpr int max_plain_schema_allowed = 260;
    static constexpr int max_schema_size = 25000;
    using ptr = std::shared_ptr<instance>;
    class listener : public dds::subscriber::listener {
    public:
        listener(ddwaf_context ctx, std::chrono::microseconds waf_timeout,
            std::string ruleset_version = {});
        listener(const listener &) = delete;
        listener &operator=(const listener &) = delete;
        listener(listener &&) noexcept;
        listener &operator=(listener &&) noexcept;
        ~listener() override;

        void call(dds::parameter_view &data, event &event) override;

        // NOLINTNEXTLINE(google-runtime-references)
        void submit_metrics(metrics::TelemetrySubmitter &msubmitter) override;

    protected:
        ddwaf_context handle_{};
        std::chrono::microseconds waf_timeout_;
        double total_runtime_{0.0};
        std::string ruleset_version_;
        std::map<std::string, std::string> schemas_;
        std::string base_tags_;
        bool rule_triggered_{};
        bool request_blocked_{};
        bool waf_hit_timeout_{};
    };

    // NOLINTNEXTLINE(google-runtime-references)
    instance(dds::parameter &rule, metrics::TelemetrySubmitter &msubmit,
        std::uint64_t waf_timeout_us,
        std::string_view key_regex = std::string_view(),
        std::string_view value_regex = std::string_view());
    instance(const instance &) = delete;
    instance &operator=(const instance &) = delete;
    instance(instance &&) noexcept;
    instance &operator=(instance &&) noexcept;
    ~instance() override;

    std::string_view get_name() override { return "waf"sv; }

    std::unordered_set<std::string> get_subscriptions() override
    {
        return addresses_;
    }

    listener::ptr get_listener() override;

    subscriber::ptr update(
        parameter &rule, metrics::TelemetrySubmitter &msubmitter) override;

    static instance::ptr from_settings(const engine_settings &settings,
        const engine_ruleset &ruleset, metrics::TelemetrySubmitter &msubmitter);

    // testing only
    static instance::ptr from_string(std::string_view rule,
        metrics::TelemetrySubmitter &msubmitter,
        std::uint64_t waf_timeout_us = default_waf_timeout_us,
        std::string_view key_regex = std::string_view(),
        std::string_view value_regex = std::string_view());

protected:
    instance(ddwaf_handle handle, metrics::TelemetrySubmitter &msubmitter,
        std::chrono::microseconds timeout, std::string version);

    ddwaf_handle handle_{nullptr};
    std::chrono::microseconds waf_timeout_;
    std::string ruleset_version_;
    std::unordered_set<std::string> addresses_;
    metrics::TelemetrySubmitter &msubmitter_; // NOLINT
};

parameter parse_file(std::string_view filename);
parameter parse_string(std::string_view config);

} // namespace dds::waf
