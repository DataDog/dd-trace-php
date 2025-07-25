// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <chrono>
#include <cstdlib>
#include <memory>
#include <optional>
#include <rapidjson/document.h>
#include <rapidjson/error/en.h>
#include <rapidjson/writer.h>
#include <string>
#include <string_view>

#include "../compression.hpp"
#include "../json_helper.hpp"
#include "../metrics.hpp"
#include "../std_logging.hpp"
#include "base.hpp"
#include "waf.hpp"
#include <base64.h>
#include <ddwaf.h>
#include <sys/syslog.h>
#include <type_traits>

namespace dds::waf {

namespace {
void handle_config_diagnostics(const remote_config::parsed_config_key &cfg_key,
    parameter_view diagnostics, telemetry::telemetry_submitter &msubmitter);
} // namespace

class waf_builder {
    static constexpr auto BUNDLED_KEY = "datadog/0/ASM_DD/0/bundled"sv;

    waf_builder(ddwaf_builder builder, parameter default_rules)
        : builder_{builder}, default_rules_{std::move(default_rules)}
    {}

public:
    static std::optional<waf_builder> create(const ddwaf_config &cfg,
        parameter default_rules, parameter &diagnostics)
    {
        auto *builder = ddwaf_builder_init(&cfg);
        if (builder == nullptr) {
            return std::nullopt;
        }

        bool const res =
            ddwaf_builder_add_or_update_config(builder, BUNDLED_KEY.data(),
                BUNDLED_KEY.size(), &default_rules, &diagnostics);

        if (res) {
            return {{builder, std::move(default_rules)}};
        }

        ddwaf_builder_destroy(builder);
        return std::nullopt;
    }

    void update(const dds::remote_config::changeset &cs, parameter &diagnostics,
        telemetry::telemetry_submitter &submitter)
    {
        for (const auto &removed : cs.removed) {
            bool const res = ddwaf_builder_remove_config(builder_.get(),
                removed.full_key().data(), removed.full_key().size());
            if (res) {
                SPDLOG_DEBUG("Removed config: {}", removed.full_key());
            } else {
                SPDLOG_WARN("Failed to remove config: {}", removed.full_key());
                std::string identifier = fmt::format(
                    "rc::{}::diagnostic", removed.product().name_lower());
                std::string tags = telemetry::telemetry_tags{}
                                       .add("log_type", identifier)
                                       .add("rc_config_id", removed.config_id())
                                       .consume();
                submitter.submit_log(
                    telemetry::telemetry_submitter::log_level::Error,
                    std::move(identifier) + "::fail_to_remove",
                    fmt::format(
                        "Failed to remove config: {}", removed.full_key()),
                    std::nullopt, std::move(tags), false);
            }
        }

        diagnostics = parameter::map();
        for (const auto &added : cs.added) {
            const remote_config::parsed_config_key &key = added.first;
            if (using_default_rules_ &&
                key.product() == remote_config::known_products::ASM_DD) {
                remove_default_config();
            }

            parameter these_diags{};
            bool const res = ddwaf_builder_add_or_update_config(builder_.get(),
                key.full_key().data(), key.full_key().size(), &added.second,
                &these_diags);
            if (res) {
                SPDLOG_DEBUG("Added/updated config: {}", key.full_key());
            } else {
                SPDLOG_WARN("Failed to add/update config {}: {}",
                    key.full_key(),
                    parameter_to_json(parameter_view{these_diags}));
            }

            handle_config_diagnostics(
                key, parameter_view{these_diags}, submitter);

            diagnostics.merge(std::move(these_diags));
        }

        auto count_asm_dd = ddwaf_builder_get_config_paths(
            builder_.get(), nullptr, "/ASM_DD/", sizeof("/ASM_DD/") - 1);
        if (count_asm_dd == 0) {
            parameter these_diags{};
            add_default_config(these_diags);
            diagnostics.merge(std::move(these_diags));
        }
    }

    waf_handle_up new_handle()
    {
        return waf_handle_up{ddwaf_builder_build_instance(builder_.get())};
    }

private:
    bool remove_default_config()
    {

        // set even on failure because failure likely means
        // the bundled rules are not loaded
        using_default_rules_ = false;
        bool const res = ddwaf_builder_remove_config(
            builder_.get(), BUNDLED_KEY.data(), BUNDLED_KEY.size());
        if (res) {
            SPDLOG_DEBUG("Removed default config");
        } else {
            SPDLOG_WARN("Failed removing default config");
        }
        return res;
    }

    bool add_default_config(parameter &diagnostics)
    {
        bool const res = ddwaf_builder_add_or_update_config(builder_.get(),
            BUNDLED_KEY.data(), BUNDLED_KEY.size(), &default_rules_,
            &diagnostics);
        if (res) {
            using_default_rules_ = true;
            SPDLOG_DEBUG("Added default config");
        } else {
            SPDLOG_WARN("Failed adding default config");
        }
        return res;
    }

    struct ddwaf_builder_deleter {
        void operator()(ddwaf_builder h) const { ddwaf_builder_destroy(h); }
    };
    std::unique_ptr<std::remove_pointer_t<ddwaf_builder>, ddwaf_builder_deleter>
        builder_;

    parameter default_rules_;
    bool using_default_rules_ = true;
};

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

void format_waf_result(
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    const ddwaf_object *actions, const ddwaf_object *events, event &event)
{
    try {
        if (actions != nullptr) {
            const parameter_view actions_pv{*actions};
            for (const auto &action : actions_pv) {
                dds::action a{
                    parse_action_type_string(std::string(action.key())), {}};
                for (const auto &parameter : action) {
                    a.parameters.emplace(parameter.key(), parameter);
                }
                event.actions.emplace_back(std::move(a));
            }
        }
        if (events != nullptr) {
            const parameter_view events_pv{*events};
            for (const auto &event_pv : events_pv) {
                event.data.emplace_back(parameter_to_json(event_pv));
            }
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

telemetry::telemetry_tags waf_update_init_report_tags(
    bool success, std::optional<std::string> rules_version)
{
    telemetry::telemetry_tags tags;
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

void waf_init_report(telemetry::telemetry_submitter &msubmitter, bool success,
    std::optional<std::string> rules_version)
{
    msubmitter.submit_metric(metrics::waf_init, 1.0,
        waf_update_init_report_tags(success, std::move(rules_version)));
}

void waf_update_report(telemetry::telemetry_submitter &msubmitter, bool success,
    std::optional<std::string> rules_version)
{
    msubmitter.submit_metric(metrics::waf_updates, 1.0,
        waf_update_init_report_tags(success, std::move(rules_version)));
}
constexpr std::array<std::string_view, 5> diagnostic_keys{
    "custom_rules",
    "exclusions",
    "rules",
    "rules_data",
    "rules_override",
};

void load_result_report(
    parameter_view diagnostics, telemetry::telemetry_submitter &msubmitter)
{
    const auto info = static_cast<parameter_view::map>(diagnostics);

    std::string_view ruleset_version{"unknown"};
    auto ruleset_version_it = info.find("ruleset_version");
    if (ruleset_version_it != info.end() &&
        ruleset_version_it->second.is_string()) {
        ruleset_version =
            static_cast<std::string_view>(ruleset_version_it->second);
    }

    for (auto k : diagnostic_keys) {
        auto it = info.find(k);
        if (it == info.end()) {
            continue;
        }

        const parameter_view &value = it->second;
        if (!value.is_map()) {
            continue;
        }

        // map has "error", "loaded", "failed", "skipped", "errors", "warnings"
        const parameter_view::map map = static_cast<parameter_view::map>(value);

        auto tags_common = [&]() {
            telemetry::telemetry_tags tags;
            tags.add("waf_version", ddwaf_get_version())
                .add("event_rules_version", ruleset_version)
                .add("config_key", k);
            return tags;
        };
        if (map.contains("error")) {
            telemetry::telemetry_tags tags{tags_common()};
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

            telemetry::telemetry_tags tags{tags_common()};
            tags.add("scope", "item");

            msubmitter.submit_metric(metrics::waf_config_errors,
                static_cast<double>(error_count), std::move(tags));
        }
    }
}

void handle_config_diagnostics(const remote_config::parsed_config_key &cfg_key,
    parameter_view diagnostics, telemetry::telemetry_submitter &msubmitter)
{
    using log_level = telemetry::telemetry_submitter::log_level;

    const auto info = static_cast<parameter_view::map>(diagnostics);
    for (auto k : diagnostic_keys) {
        auto it = info.find(k);
        if (it == info.end()) {
            continue;
        }

        const parameter_view &value = it->second;
        if (!value.is_map()) {
            continue;
        }

        // map has "error", "loaded", "failed", "skipped", "errors", "warnings"
        const parameter_view::map map = static_cast<parameter_view::map>(value);

        if (map.contains("error")) {
            auto message = static_cast<std::string>(map.at("error"));
            std::string identifier = fmt::format(
                "rc::{}::diagnostic", cfg_key.product().name_lower());
            std::string tags = telemetry::telemetry_tags{}
                                   .add("log_type", identifier)
                                   .add("appsec_config_key", k)
                                   .add("rc_config_id", cfg_key.config_id())
                                   .consume();

            msubmitter.submit_log(log_level::Error,
                std::move(identifier) + "::error", std::move(message),
                std::nullopt, std::move(tags), false);
        }
        if (map.contains("errors")) {
            parameter_view const errors = map.at("errors");
            if (errors.type() == parameter_type::map && errors.size() > 0) {
                std::string message = parameter_to_json(map.at("errors"));
                std::string identifier = fmt::format(
                    "rc::{}::diagnostic", cfg_key.product().name_lower());
                std::string tags = telemetry::telemetry_tags{}
                                       .add("log_type", identifier)
                                       .add("appsec_config_key", k)
                                       .add("rc_config_id", cfg_key.config_id())
                                       .consume();

                msubmitter.submit_log(log_level::Error,
                    std::move(identifier) + "::errors", std::move(message),
                    std::nullopt, std::move(tags), false);
            }
        }
        if (map.contains("warnings")) {
            parameter_view const warnings = map.at("warnings");
            if (warnings.type() == parameter_type::map && warnings.size() > 0) {
                std::string message = parameter_to_json(map.at("warnings"));
                std::string identifier = fmt::format(
                    "rc::{}::diagnostic", cfg_key.product().name_lower());
                std::string tags = telemetry::telemetry_tags{}
                                       .add("log_type", identifier)
                                       .add("appsec_config_key", k)
                                       .add("rc_config_id", cfg_key.config_id())
                                       .consume();

                msubmitter.submit_log(log_level::Warn,
                    std::move(identifier) + "::warnings", std::move(message),
                    std::nullopt, std::move(tags), false);
            }
        }
    }
}

void load_result_report_legacy(parameter_view diagnostics, std::string &version,
    telemetry::telemetry_submitter &msubmitter)
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
    ddwaf_object res;
    DDWAF_RET_CODE code;
    unsigned duration = 0;
    bool timeout = false;
    const ddwaf_object *events = nullptr;
    const ddwaf_object *actions = nullptr;
    const ddwaf_object *attributes = nullptr;
    auto run_waf = [&]() {
        dds::parameter_view *persistent = rasp_rule.empty() ? &data : nullptr;
        dds::parameter_view *ephemeral = rasp_rule.empty() ? nullptr : &data;
        code = ddwaf_run(
            handle_, persistent, ephemeral, &res, waf_timeout_.count());
        for (size_t i = 0; i < ddwaf_object_size(&res); ++i) {
            const ddwaf_object *child = ddwaf_object_get_index(&res, i);
            if (child == nullptr) {
                continue;
            }

            size_t length = 0;
            const char *key = ddwaf_object_get_key(child, &length);
            if (key == nullptr) {
                continue;
            }

            const std::string_view key_sv{key, length};
            if (key_sv == "events"sv) {
                events = child;
            } else if (key_sv == "actions"sv) {
                actions = child;
            } else if (key_sv == "attributes"sv) {
                attributes = child;
            } else if (key_sv == "keep"sv) {
                event.keep = ddwaf_object_get_bool(child);
            } else if (key_sv == "duration"sv) {
                duration = ddwaf_object_get_unsigned(child);
            } else if (key_sv == "timeout"sv) {
                timeout = ddwaf_object_get_bool(child);
            }
        }
    };
    if (spdlog::should_log(spdlog::level::debug)) {
        DD_STDLOG(DD_STDLOG_CALLING_WAF, data.debug_str());
        run_waf();

        static constexpr unsigned millis = 1e6;
        // This converts the events to JSON which is already done in the
        // switch below so it's slightly inefficient, albeit since it's only
        // done on debug, we can live with it...
        std::string events_json;
        if (events != nullptr) {
            events_json = parameter_to_json(parameter_view{*events});
            DD_STDLOG(DD_STDLOG_AFTER_WAF, events_json, duration / millis);
        }
        SPDLOG_DEBUG("Waf response: code {} - keep {} - duration {} - timeout "
                     "{} - actions {} - attributes {} - events {}",
            fmt::underlying(code), event.keep, duration / millis, timeout,
            actions != nullptr ? parameter_to_json(parameter_view{*actions})
                               : "",
            attributes != nullptr
                ? parameter_to_json(parameter_view{*attributes})
                : "",
            events_json);
    } else {
        run_waf();
    }
    // Free result on exception/return
    const std::unique_ptr<ddwaf_object, decltype(&ddwaf_object_free)> scope(
        &res, ddwaf_object_free);
    if (rasp_rule.empty()) {
        // RASP WAF call should not be counted on total_runtime_
        // NOLINTNEXTLINE
        total_runtime_ += duration / 1000.0;
    }
    if (timeout) {
        waf_hit_timeout_ = true;
    }
    if (actions != nullptr) {
        const parameter_view actions_pv{*actions};
        for (const auto &action : actions_pv) {
            const std::string_view action_type = action.key();
            if (action_type == "block_request" ||
                action_type == "redirect_request") {
                request_blocked_ = true;
                break;
            }
        }
    }
    if (!rasp_rule.empty()) {
        // NOLINTNEXTLINE
        rasp_runtime_ += duration / 1000.0;
        rasp_calls_++;
        if (timeout) {
            rasp_timeouts_ += 1;
            rasp_metrics_[rasp_rule].timeouts++;
        }
        rasp_metrics_[rasp_rule].evaluated++;
        if (code == DDWAF_MATCH) {
            rasp_metrics_[rasp_rule].matches++;
        } else if (code != DDWAF_OK) {
            rasp_metrics_[rasp_rule].errors++;
        }
    }
    if (attributes != nullptr) {
        const parameter_view attributes_pv{*attributes};
        for (const parameter_view &derivative : attributes_pv) {
            if (derivative.key().starts_with("_dd.appsec.s.")) {
                std::string json_derivative = parameter_to_json(derivative);
                if (json_derivative.length() > max_plain_schema_allowed) {
                    auto encoded = compress(json_derivative);
                    if (encoded) {
                        json_derivative = base64_encode(encoded.value(), false);
                    }
                }
                if (json_derivative.length() <= max_schema_size) {
                    meta_attributes_.emplace(
                        derivative.key(), std::move(json_derivative));
                } else {
                    SPDLOG_WARN("Schema for key {} is too large to submit",
                        derivative.key());
                }
            } else {
                if (derivative.is_signed()) {
                    auto value =
                        static_cast<double>(static_cast<int64_t>(derivative));
                    metrics_attributes_.emplace(derivative.key(), value);
                } else if (derivative.is_unsigned()) {
                    auto value =
                        static_cast<double>(static_cast<uint64_t>(derivative));
                    metrics_attributes_.emplace(derivative.key(), value);
                } else {
                    meta_attributes_.emplace(derivative.key(), derivative);
                }
            }
        }
    }
    switch (code) {
    case DDWAF_MATCH:
        rule_triggered_ = true;
        return format_waf_result(actions, events, event);
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
        if (timeout) {
            waf_hit_timeout_ = true;
            throw timeout_error();
        }
        break;
    default:
        break;
    }
}

void instance::listener::submit_metrics(
    telemetry::telemetry_submitter &msubmitter)
{
    telemetry::telemetry_tags tags = base_tags_;
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
        tags.add("waf_error", "true");
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
            telemetry::telemetry_tags tags = base_tags_;
            tags.add("rule_type", rule.first);
            msubmitter.submit_metric(
                metrics::telemetry_rasp_rule_eval, rule.second.evaluated, tags);
            msubmitter.submit_metric(
                metrics::telemetry_rasp_rule_match, rule.second.matches, tags);
            msubmitter.submit_metric(
                metrics::telemetry_rasp_timeout, rule.second.timeouts, tags);
            msubmitter.submit_metric(
                metrics::telemetry_rasp_error, rule.second.errors, tags);
        }
    }

    for (const auto &[key, value] : meta_attributes_) {
        msubmitter.submit_span_meta_copy_key(
            std::string{key}, std::string(value));
    }

    for (const auto &[key, value] : metrics_attributes_) {
        msubmitter.submit_span_metric(key, value);
    }
}

instance::instance(parameter rules, telemetry::telemetry_submitter &msubmit,
    std::uint64_t waf_timeout_us, std::string_view key_regex,
    std::string_view value_regex)
    : waf_timeout_{waf_timeout_us}, msubmitter_{msubmit}
{
    const ddwaf_config config{
        {0, 0, 0}, {key_regex.data(), value_regex.data()}, nullptr};

    parameter diagnostics{};
    auto maybe_builder =
        waf_builder::create(config, std::move(rules), diagnostics);

    if (maybe_builder) {
        builder_ = std::make_shared<waf_builder>(std::move(*maybe_builder));
        handle_ = builder_->new_handle();
    }

    load_result_report(parameter_view{diagnostics}, msubmit);
    load_result_report_legacy(
        parameter_view{diagnostics}, ruleset_version_, msubmit);
    waf_init_report(msubmit, handle_ != nullptr,
        ruleset_version_.empty() ? std::nullopt
                                 : std::make_optional(ruleset_version_));

    if (handle_ == nullptr) {
        throw invalid_object();
    }

    uint32_t size;
    const auto *addrs = ddwaf_known_addresses(handle_.get(), &size);

    addresses_.clear();
    for (uint32_t i = 0; i < size; i++) { addresses_.emplace(addrs[i]); }
}

instance::instance(std::shared_ptr<waf_builder> builder, waf_handle_up handle,
    telemetry::telemetry_submitter &msubmitter,
    std::chrono::microseconds timeout, std::string version)
    : builder_{std::move(builder)}, handle_{std::move(handle)},
      waf_timeout_{timeout}, ruleset_version_{std::move(version)},
      msubmitter_{msubmitter}
{
    uint32_t size;
    const auto *addrs = ddwaf_known_addresses(handle_.get(), &size);

    addresses_.clear();
    for (uint32_t i = 0; i < size; i++) { addresses_.emplace(addrs[i]); }
}

instance::instance(instance &&other) noexcept
    : builder_{std::move(other.builder_)}, handle_(std::move(other.handle_)),
      waf_timeout_(other.waf_timeout_),
      ruleset_version_(std::move(other.ruleset_version_)),
      addresses_(std::move(other.addresses_)), msubmitter_(other.msubmitter_)
{
    other.waf_timeout_ = {};
}

instance &instance::operator=(instance &&other) noexcept
{
    builder_ = std::move(other.builder_);
    handle_ = std::move(other.handle_);

    waf_timeout_ = other.waf_timeout_;
    other.waf_timeout_ = {};

    ruleset_version_ = std::move(other.ruleset_version_);
    addresses_ = std::move(other.addresses_);

    return *this;
}

std::unique_ptr<subscriber::listener> instance::get_listener()
{
    return std::make_unique<listener>(
        ddwaf_context_init(handle_.get()), waf_timeout_, ruleset_version_);
}

std::unique_ptr<subscriber> instance::update(
    const remote_config::changeset &changeset,
    telemetry::telemetry_submitter &msubmitter)
{
    parameter diagnostics;
    builder_->update(changeset, diagnostics, msubmitter);

    waf_handle_up new_handle = builder_->new_handle();

    std::string version;
    {
        load_result_report(parameter_view{diagnostics}, msubmitter);
        load_result_report_legacy(
            parameter_view{diagnostics}, version, msubmitter);
        if (version.empty()) {
            version = ruleset_version_;
        }

        waf_update_report(msubmitter, new_handle != nullptr, version);
    }

    if (new_handle == nullptr) {
        throw invalid_object();
    }

    return std::unique_ptr<subscriber>(new instance(builder_,
        std::move(new_handle), msubmitter_, waf_timeout_, std::move(version)));
}

std::unique_ptr<instance> instance::from_settings(
    const engine_settings &settings, parameter ruleset,
    telemetry::telemetry_submitter &msubmitter)
{
    return std::make_unique<instance>(std::move(ruleset), msubmitter,
        settings.waf_timeout_us, settings.obfuscator_key_regex,
        settings.obfuscator_value_regex);
}

std::unique_ptr<instance> instance::from_string(std::string_view rule,
    telemetry::telemetry_submitter &msubmitter, std::uint64_t waf_timeout_us,
    std::string_view key_regex, std::string_view value_regex)
{
    rapidjson::Document doc;
    rapidjson::ParseResult const result = doc.Parse(rule.data(), rule.size());
    if ((result == nullptr) || !doc.IsObject()) {
        throw parsing_error("invalid json rule");
    }
    dds::parameter param = json_to_parameter(doc);

    return std::make_unique<instance>(
        std::move(param), msubmitter, waf_timeout_us, key_regex, value_regex);
}

} // namespace dds::waf
