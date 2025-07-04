// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../engine_settings.hpp"
#include "../metrics.hpp"
#include "../parameter.hpp"
#include "base.hpp"
#include <chrono>
#include <ddwaf.h>
#include <spdlog/spdlog.h>
#include <string>
#include <string_view>
#include <type_traits>
#include <unordered_map>

namespace dds::waf {

void initialise_logging(spdlog::level::level_enum level);

class waf_builder;

struct ddwaf_handle_deleter {
    void operator()(ddwaf_handle h) const { ddwaf_destroy(h); }
};

using waf_handle_up =
    std::unique_ptr<std::remove_pointer_t<ddwaf_handle>, ddwaf_handle_deleter>;

class instance : public dds::subscriber {
public:
    static constexpr int default_waf_timeout_us = 10000;
    static constexpr int max_plain_schema_allowed = 260;
    static constexpr int max_schema_size = 25000;

    class listener : public dds::subscriber::listener {
    public:
        listener(ddwaf_context ctx, std::chrono::microseconds waf_timeout,
            std::string ruleset_version = {});
        listener(const listener &) = delete;
        listener &operator=(const listener &) = delete;
        listener(listener &&) noexcept;
        listener &operator=(listener &&) noexcept;
        ~listener() override;

        void call(dds::parameter_view &data, event &event,
            const std::string &rasp_rule) override;

        // NOLINTNEXTLINE(google-runtime-references)
        void submit_metrics(
            telemetry::telemetry_submitter &msubmitter) override;

    protected:
        struct rasp_telemetry_metrics {
            unsigned evaluated = 0;
            unsigned matches = 0;
            unsigned timeouts = 0;
            unsigned errors = 0;
        };
        std::unordered_map<std::string, rasp_telemetry_metrics> rasp_metrics_ =
            {};
        ddwaf_context handle_{};
        std::chrono::microseconds waf_timeout_;
        double total_runtime_{0.0};
        std::string ruleset_version_;
        bool rasp_request_ = false;
        double rasp_runtime_{0.0};
        unsigned rasp_calls_{0};
        unsigned rasp_timeouts_{0};
        DDWAF_RET_CODE code_{DDWAF_OK};
        std::map<std::string, std::string> attributes_;
        telemetry::telemetry_tags base_tags_;
        bool rule_triggered_{};
        bool request_blocked_{};
        bool waf_hit_timeout_{};
        bool waf_run_error_{};
    };

    // NOLINTNEXTLINE(google-runtime-references)
    instance(dds::parameter rule, telemetry::telemetry_submitter &msubmit,
        std::uint64_t waf_timeout_us,
        std::string_view key_regex = std::string_view(),
        std::string_view value_regex = std::string_view());
    instance(const instance &) = delete;
    instance &operator=(const instance &) = delete;
    instance(instance &&) noexcept;
    instance &operator=(instance &&) noexcept;
    ~instance() override = default;

    std::string_view get_name() override { return "waf"sv; }

    std::unordered_set<std::string> get_subscriptions() override
    {
        return addresses_;
    }

    std::unique_ptr<subscriber::listener> get_listener() override;

    std::unique_ptr<subscriber> update(
        const remote_config::changeset &changeset,
        telemetry::telemetry_submitter &msubmitter) override;

    static std::unique_ptr<instance> from_settings(
        const engine_settings &settings, parameter ruleset,
        telemetry::telemetry_submitter &msubmitter);

    // testing only
    static std::unique_ptr<instance> from_string(std::string_view rule,
        telemetry::telemetry_submitter &msubmitter,
        std::uint64_t waf_timeout_us = default_waf_timeout_us,
        std::string_view key_regex = std::string_view(),
        std::string_view value_regex = std::string_view());

protected:
    instance(std::shared_ptr<waf_builder> builder, waf_handle_up handle,
        telemetry::telemetry_submitter &msubmitter,
        std::chrono::microseconds timeout, std::string version);

    static constexpr std::string_view BUILTIN_RULES_KEY = "ASM_DD/builtin";

    std::shared_ptr<waf_builder> builder_;
    waf_handle_up handle_{nullptr};
    std::chrono::microseconds waf_timeout_;
    std::string ruleset_version_;
    std::unordered_set<std::string> addresses_;
    telemetry::telemetry_submitter &msubmitter_; // NOLINT
};

parameter parse_file(std::string_view filename);
parameter parse_string(std::string_view config);

} // namespace dds::waf
