// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <atomic>
#include <chrono>
#include <cstdlib>
#include <limits>
#include <memory>
#include <optional>
#include <rapidjson/document.h>
#include <rapidjson/error/en.h>
#include <rapidjson/writer.h>
#include <stdexcept>
#include <string_view>

#include "../compression.hpp"
#include "../json_helper.hpp"
#include "../metrics.hpp"
#include "../std_logging.hpp"
#include "base.hpp"
#include "waf.hpp"
#include <base64.h>
#include <ddwaf.h>

namespace dds::waf {

namespace {

action_type parse_action_type_string(const std::string &action)
{
    if (action == "block_request") {
        return action_type::block;
    }

    if (action == "redirect_request") {
        return action_type::redirect;
    }

    if (action == "generate_stack") {
        return action_type::stack_trace;
    }

    if (action == "generate_schema") {
        return action_type::extract_schema;
    }

    return action_type::invalid;
}

void format_waf_result(ddwaf_result &res, event &event)
{
    try {
        const parameter_view actions{res.actions};
        for (const auto &action : actions) {
            dds::action a{
                parse_action_type_string(std::string(action.key())), {}};
            for (const auto &parameter : action) {
                a.parameters.emplace(parameter.key(), parameter);
            }
            event.actions.emplace_back(std::move(a));
        }

        const parameter_view events{res.events};
        for (const auto &event_pv : events) {
            event.data.emplace_back(std::move(parameter_to_json(event_pv)));
        }

    } catch (const std::exception &e) {
        SPDLOG_ERROR("failed to parse WAF output: {}", e.what());
    }
}

DDWAF_LOG_LEVEL spdlog_level_to_ddwaf(spdlog::level::level_enum level)
{
    switch (level) {
    case spdlog::level::trace:
        return DDWAF_LOG_TRACE;
    case spdlog::level::debug:
    // libddwaf is too verbose at debug level
    case spdlog::level::info:
        return DDWAF_LOG_INFO;
    case spdlog::level::warn:
        return DDWAF_LOG_WARN;
    case spdlog::level::err:
        [[fallthrough]];
    case spdlog::level::critical:
        return DDWAF_LOG_ERROR;
    case spdlog::level::off:
        [[fallthrough]];
    default:
        break;
    }
    return DDWAF_LOG_OFF;
}

void log_cb(DDWAF_LOG_LEVEL level, const char *function, const char *file,
    unsigned line, const char *message, uint64_t message_len)
{
    auto new_level = spdlog::level::off;
    switch (level) {
    case DDWAF_LOG_TRACE:
    case DDWAF_LOG_DEBUG:
        new_level = spdlog::level::trace;
        break;
    case DDWAF_LOG_INFO:
        new_level = spdlog::level::info;
        break;
    case DDWAF_LOG_WARN:
        new_level = spdlog::level::warn;
        break;
    case DDWAF_LOG_ERROR:
        new_level = spdlog::level::err;
        break;
    case DDWAF_LOG_OFF:
        [[fallthrough]];
    default:
        break;
    }

    spdlog::default_logger()->log(
        spdlog::source_loc{file, static_cast<int>(line), function}, new_level,
        std::string_view(message, message_len));
}

metrics::telemetry_tags waf_update_init_report_tags(
    bool success, std::optional<std::string> rules_version)
{
    metrics::telemetry_tags tags;
    if (success) {
        tags.add("success", "true");
    } else {
        tags.add("success", "false");
    }
    if (rules_version) {
        tags.add("event_rules_version", rules_version.value());
    }
    tags.add("waf_version", ddwaf_get_version());
    return tags;
}

void waf_init_report(metrics::telemetry_submitter &msubmitter, bool success,
    std::optional<std::string> rules_version)
{
    msubmitter.submit_metric(metrics::waf_init, 1.0,
        waf_update_init_report_tags(success, std::move(rules_version)));
}

void waf_update_report(metrics::telemetry_submitter &msubmitter, bool success,
    std::optional<std::string> rules_version)
{
    msubmitter.submit_metric(metrics::waf_updates, 1.0,
        waf_update_init_report_tags(success, std::move(rules_version)));
}

void load_result_report(
    parameter_view diagnostics, metrics::telemetry_submitter &msubmitter)
{
    const auto info = static_cast<parameter_view::map>(diagnostics);

    std::string_view ruleset_version{"unknown"};
    auto ruleset_version_it = info.find("ruleset_version");
    if (ruleset_version_it != info.end() &&
        ruleset_version_it->second.is_string()) {
        ruleset_version =
            static_cast<std::string_view>(ruleset_version_it->second);
    }

    static constexpr std::array<std::string_view, 5> keys{
        "custom_rules",
        "exclusions",
        "rules",
        "rules_data",
        "rules_override",
    };

    for (auto k : keys) {
        auto it = info.find(k);
        if (it == info.end()) {
            continue;
        }

        const parameter_view &value = it->second;
        if (!value.is_map()) {
            continue;
        }

        // map has either "error" key or "loaded", "failed", "errors" keys
        const parameter_view::map map = static_cast<parameter_view::map>(value);

        auto tags_common = [&]() {
            metrics::telemetry_tags tags;
            tags.add("waf_version", ddwaf_get_version())
                .add("event_rules_version", ruleset_version)
                .add("config_key", k);
            return tags;
        };
        if (map.contains("error")) {
            metrics::telemetry_tags tags{tags_common()};
            tags.add("scope", "top-level");

            msubmitter.submit_metric(
                metrics::waf_config_errors, 1.0, std::move(tags));
        } else if (map.contains("errors")) {
            const auto errors_map = map.at("errors");
            if (errors_map.size() == 0) {
                continue;
            }
            std::uint64_t error_count = 0;
            for (const parameter_view &err : errors_map) {
                // key is error message, value is array of ids
                assert(err.type() == parameter_type::array);
                error_count += err.size();
            }

            metrics::telemetry_tags tags{tags_common()};
            tags.add("scope", "item");

            msubmitter.submit_metric(metrics::waf_config_errors,
                static_cast<double>(error_count), std::move(tags));
        }
    }
}

void load_result_report_legacy(parameter_view diagnostics, std::string &version,
    metrics::telemetry_submitter &msubmitter)
{
    try {
        const parameter_view diagnostics_view{diagnostics};
        auto info = static_cast<parameter_view::map>(diagnostics_view);

        auto rules_it = info.find("rules");
        if (rules_it != info.end()) {
            auto rules = static_cast<parameter_view::map>(rules_it->second);
            auto it = rules.find("loaded");
            if (it != rules.end()) {
                msubmitter.submit_span_metric(metrics::event_rules_loaded,
                    static_cast<double>(it->second.size()));
            }

            it = rules.find("failed");
            if (it != rules.end()) {
                msubmitter.submit_span_metric(metrics::event_rules_failed,
                    static_cast<double>(it->second.size()));
            }

            it = rules.find("errors");
            if (it != rules.end()) {
                msubmitter.submit_span_meta(
                    metrics::event_rules_errors, parameter_to_json(it->second));
            }
        }

        msubmitter.submit_span_meta(metrics::waf_version, ddwaf_get_version());

        auto version_it = info.find("ruleset_version");
        if (version_it != info.end()) {
            version = std::string(version_it->second);
        }
    } catch (const std::exception &e) {
        SPDLOG_ERROR("Failed to parse WAF tags and metrics: {}", e.what());
    }
}

} // namespace

void initialise_logging(spdlog::level::level_enum level)
{
    ddwaf_set_log_cb(log_cb, spdlog_level_to_ddwaf(level));
}

instance::listener::listener(ddwaf_context ctx,
    std::chrono::microseconds waf_timeout, std::string ruleset_version)
    : handle_{ctx}, waf_timeout_{waf_timeout},
      ruleset_version_{std::move(ruleset_version)}
{
    base_tags_.add("event_rules_version", ruleset_version_);
    base_tags_.add("waf_version", ddwaf_get_version());
}

instance::listener::listener(instance::listener &&other) noexcept
    : handle_{other.handle_}, waf_timeout_{other.waf_timeout_}
{
    other.handle_ = nullptr;
    other.waf_timeout_ = {};
}

instance::listener &instance::listener::operator=(listener &&other) noexcept
{
    handle_ = other.handle_;
    other.handle_ = nullptr;
    return *this;
}

instance::listener::~listener()
{
    if (handle_ != nullptr) {
        ddwaf_context_destroy(handle_);
    }
}

void instance::listener::call(
    dds::parameter_view &data, event &event, const std::string &rasp_rule)
{
    ddwaf_result res;
    DDWAF_RET_CODE code;
    auto run_waf = [&]() {
        dds::parameter_view *persistent = rasp_rule.empty() ? &data : nullptr;
        dds::parameter_view *ephemeral = rasp_rule.empty() ? nullptr : &data;
        code = ddwaf_run(
            handle_, persistent, ephemeral, &res, waf_timeout_.count());
    };

    if (spdlog::should_log(spdlog::level::debug)) {
        DD_STDLOG(DD_STDLOG_CALLING_WAF, data.debug_str());
        run_waf();

        static constexpr unsigned millis = 1e6;
        // This converts the events to JSON which is already done in the
        // switch below so it's slightly inefficient, albeit since it's only
        // done on debug, we can live with it...
        DD_STDLOG(DD_STDLOG_AFTER_WAF,
            parameter_to_json(parameter_view{res.events}),
            res.total_runtime / millis);
        SPDLOG_DEBUG("Waf response: code {} - actions {} - derivatives {}",
            fmt::underlying(code),
            parameter_to_json(parameter_view{res.actions}),
            parameter_to_json(parameter_view{res.derivatives}));
    } else {
        run_waf();
    }

    // Free result on exception/return
    const std::unique_ptr<ddwaf_result, decltype(&ddwaf_result_free)> scope(
        &res, ddwaf_result_free);

    if (rasp_rule.empty()) {
        // RASP WAF call should not be counted on total_runtime_
        // NOLINTNEXTLINE
        total_runtime_ += res.total_runtime / 1000.0;
    }
    if (res.timeout) {
        waf_hit_timeout_ = true;
    }
    const parameter_view actions{res.actions};
    for (const auto &action : actions) {
        const std::string_view action_type = action.key();
        if (action_type == "block_request" ||
            action_type == "redirect_request") {
            request_blocked_ = true;
            break;
        }
    }
    if (!rasp_rule.empty()) {
        // NOLINTNEXTLINE
        rasp_runtime_ += res.total_runtime / 1000.0;
        rasp_calls_++;
        if (res.timeout) {
            rasp_timeouts_ += 1;
            rasp_metrics_[rasp_rule].timeouts++;
        }
        rasp_metrics_[rasp_rule].evaluated++;
        if (code == DDWAF_MATCH) {
            rasp_metrics_[rasp_rule].matches++;
        }
    }

    const parameter_view derivatives{res.derivatives};
    for (const auto &derivative : derivatives) {
        if (derivative.key().starts_with("_dd.appsec.s.")) {
            derivatives_.emplace(
                derivative.key(), std::move(parameter_to_json(derivative)));
        } else {
            derivatives_.emplace(derivative.key(), derivative);
        }
    }

    switch (code) {
    case DDWAF_MATCH:
        rule_triggered_ = true;
        return format_waf_result(res, event);
    case DDWAF_ERR_INTERNAL:
        waf_run_error_ = true;
        throw internal_error();
    case DDWAF_ERR_INVALID_OBJECT:
        waf_run_error_ = true;
        throw invalid_object();
    case DDWAF_ERR_INVALID_ARGUMENT:
        waf_run_error_ = true;
        throw invalid_argument();
    case DDWAF_OK:
        if (res.timeout) {
            waf_hit_timeout_ = true;
            throw timeout_error();
        }
        break;
    default:
        break;
    }
}

void instance::listener::submit_metrics(
    metrics::telemetry_submitter &msubmitter)
{
    metrics::telemetry_tags tags = base_tags_;
    if (rule_triggered_) {
        tags.add("rule_triggered", "true");
    }
    if (request_blocked_) {
        tags.add("request_blocked", "true");
    }
    if (waf_hit_timeout_) {
        tags.add("waf_timeout", "true");
    }
    if (waf_run_error_) {
        tags.add("waf_run_error", "true");
    }
    msubmitter.submit_metric(metrics::waf_requests, 1.0, std::move(tags));

    // span tags/metrics
    msubmitter.submit_span_meta(
        metrics::event_rules_version, std::string{ruleset_version_});
    msubmitter.submit_span_metric(metrics::waf_duration, total_runtime_);

    if (rasp_calls_ > 0) {
        msubmitter.submit_span_metric(metrics::rasp_duration, rasp_runtime_);
        msubmitter.submit_span_metric(metrics::rasp_rule_eval, rasp_calls_);
        if (rasp_timeouts_ > 0) {
            msubmitter.submit_span_metric(
                metrics::rasp_timeout, rasp_timeouts_);
        }

        for (auto const &rule : rasp_metrics_) {
            metrics::telemetry_tags tags;
            tags.add("rule_type", rule.first);
            tags.add("waf_version", ddwaf_get_version());
            msubmitter.submit_metric(
                metrics::telemetry_rasp_rule_eval, rule.second.evaluated, tags);
            msubmitter.submit_metric(
                metrics::telemetry_rasp_rule_match, rule.second.matches, tags);
            msubmitter.submit_metric(
                metrics::telemetry_rasp_timeout, rule.second.timeouts, tags);
        }
    }

    for (const auto &[key, value] : derivatives_) {
        std::string derivative = value;
        if (value.length() > max_plain_schema_allowed &&
            key.starts_with("_dd.appsec.s.")) {
            auto encoded = compress(derivative);
            if (encoded) {
                derivative = base64_encode(encoded.value(), false);
            }
        }

        if (derivative.length() <= max_schema_size) {
            msubmitter.submit_span_meta_copy_key(key, std::move(derivative));
        } else {
            SPDLOG_WARN("Schema for key {} is too large to submit", key);
        }
    }
}

instance::instance(parameter &rule, metrics::telemetry_submitter &msubmit,
    std::uint64_t waf_timeout_us, std::string_view key_regex,
    std::string_view value_regex)
    : waf_timeout_{waf_timeout_us}, msubmitter_{msubmit}
{
    const ddwaf_config config{
        {0, 0, 0}, {key_regex.data(), value_regex.data()}, nullptr};

    ddwaf_object diagnostics;
    handle_ = ddwaf_init(rule, &config, &diagnostics);

    load_result_report(parameter_view{diagnostics}, msubmit);
    load_result_report_legacy(
        parameter_view{diagnostics}, ruleset_version_, msubmit);
    waf_init_report(msubmit, handle_ != nullptr,
        ruleset_version_.empty() ? std::nullopt
                                 : std::make_optional(ruleset_version_));

    ddwaf_object_free(&diagnostics);

    if (handle_ == nullptr) {
        throw invalid_object();
    }

    uint32_t size;
    const auto *addrs = ddwaf_known_addresses(handle_, &size);

    addresses_.clear();
    for (uint32_t i = 0; i < size; i++) { addresses_.emplace(addrs[i]); }
}

instance::instance(ddwaf_handle handle,
    metrics::telemetry_submitter &msubmitter, std::chrono::microseconds timeout,
    std::string version)
    : handle_{handle}, msubmitter_{msubmitter}, waf_timeout_{timeout},
      ruleset_version_{std::move(version)}
{
    uint32_t size;
    const auto *addrs = ddwaf_known_addresses(handle_, &size);

    addresses_.clear();
    for (uint32_t i = 0; i < size; i++) { addresses_.emplace(addrs[i]); }
}

instance::instance(instance &&other) noexcept
    : handle_(other.handle_), msubmitter_(other.msubmitter_),
      waf_timeout_(other.waf_timeout_),
      ruleset_version_(std::move(other.ruleset_version_)),
      addresses_(std::move(other.addresses_))
{
    other.handle_ = nullptr;
    other.waf_timeout_ = {};
}

instance &instance::operator=(instance &&other) noexcept
{
    handle_ = other.handle_;
    other.handle_ = nullptr;

    waf_timeout_ = other.waf_timeout_;
    other.waf_timeout_ = {};

    ruleset_version_ = std::move(other.ruleset_version_);
    addresses_ = std::move(other.addresses_);

    return *this;
}

instance::~instance()
{
    if (handle_ != nullptr) {
        ddwaf_destroy(handle_);
    }
}

std::unique_ptr<subscriber::listener> instance::get_listener()
{
    return std::make_unique<listener>(
        ddwaf_context_init(handle_), waf_timeout_, ruleset_version_);
}

std::unique_ptr<subscriber> instance::update(
    parameter &rule, metrics::telemetry_submitter &msubmitter)
{
    ddwaf_object diagnostics;
    auto *new_handle = ddwaf_update(handle_, rule, &diagnostics);

    std::string version;
    {
        load_result_report(parameter_view{diagnostics}, msubmitter);
        load_result_report_legacy(
            parameter_view{diagnostics}, version, msubmitter);
        if (version.empty()) {
            version = ruleset_version_;
        }
        ddwaf_object_free(&diagnostics);

        waf_update_report(msubmitter, new_handle != nullptr, version);
    }

    if (new_handle == nullptr) {
        throw invalid_object();
    }

    return std::unique_ptr<subscriber>(new instance(
        new_handle, msubmitter_, waf_timeout_, std::move(version)));
}

std::unique_ptr<instance> instance::from_settings(
    const engine_settings &settings, const engine_ruleset &ruleset,
    metrics::telemetry_submitter &msubmitter)
{
    dds::parameter param = json_to_parameter(ruleset.get_document());
    return std::make_unique<instance>(param, msubmitter,
        settings.waf_timeout_us, settings.obfuscator_key_regex,
        settings.obfuscator_value_regex);
}

std::unique_ptr<instance> instance::from_string(std::string_view rule,
    metrics::telemetry_submitter &msubmitter, std::uint64_t waf_timeout_us,
    std::string_view key_regex, std::string_view value_regex)
{
    engine_ruleset const ruleset{rule};
    dds::parameter param = json_to_parameter(ruleset.get_document());
    return std::make_unique<instance>(
        param, msubmitter, waf_timeout_us, key_regex, value_regex);
}

} // namespace dds::waf
