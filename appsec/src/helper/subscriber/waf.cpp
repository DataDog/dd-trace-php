// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "../std_logging.hpp"
#include "ddwaf.h"
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

#include "../json_helper.hpp"
#include "../tags.hpp"
#include "waf.hpp"

namespace dds::waf {

namespace {

dds::subscriber::event format_waf_result(ddwaf_result &res)
{
    dds::subscriber::event output;
    try {
        const parameter_view actions{res.actions};
        for (const auto &action : actions) {
            output.actions.emplace(std::string{action});
        }

        const parameter_view events{res.events};
        for (const auto &event : events) {
            output.data.emplace_back(std::move(parameter_to_json(event)));
        }
    } catch (const std::exception &e) {
        SPDLOG_ERROR("failed to parse WAF output: {}", e.what());
    }
    return output;
}

DDWAF_LOG_LEVEL spdlog_level_to_ddwaf(spdlog::level::level_enum level)
{
    switch (level) {
    case spdlog::level::trace:
        return DDWAF_LOG_TRACE;
    case spdlog::level::debug:
        return DDWAF_LOG_DEBUG;
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
        new_level = spdlog::level::trace;
        break;
    case DDWAF_LOG_DEBUG:
        new_level = spdlog::level::debug;
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

void extract_tags_and_metrics(parameter_view diagnostics, std::string &version,
    std::map<std::string_view, std::string> &meta,
    std::map<std::string_view, double> &metrics)
{
    try {
        const parameter_view diagnostics_view{diagnostics};
        auto info = static_cast<parameter_view::map>(diagnostics_view);

        auto rules_it = info.find("rules");
        if (rules_it != info.end()) {
            auto rules = static_cast<parameter_view::map>(rules_it->second);
            auto it = rules.find("loaded");
            if (it != rules.end()) {
                metrics[tag::event_rules_loaded] =
                    static_cast<double>(it->second.size());
            }

            it = rules.find("failed");
            if (it != rules.end()) {
                metrics[tag::event_rules_failed] =
                    static_cast<double>(it->second.size());
            }

            it = rules.find("errors");
            if (it != rules.end()) {
                meta[tag::event_rules_errors] = parameter_to_json(it->second);
            }
        }

        meta[tag::waf_version] = ddwaf_get_version();

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
    std::chrono::microseconds waf_timeout, std::string_view ruleset_version)
    : handle_{ctx}, waf_timeout_{waf_timeout}, ruleset_version_(ruleset_version)
{}

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

std::optional<subscriber::event> instance::listener::call(
    dds::parameter_view &data)
{
    ddwaf_result res;
    DDWAF_RET_CODE code;
    auto run_waf = [&]() {
        code = ddwaf_run(handle_, data, &res, waf_timeout_.count());
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
    } else {
        run_waf();
    }

    // Free result on exception/return
    const std::unique_ptr<ddwaf_result, decltype(&ddwaf_result_free)> scope(
        &res, ddwaf_result_free);

    // NOLINTNEXTLINE
    total_runtime_ += res.total_runtime / 1000.0;

    switch (code) {
    case DDWAF_MATCH:
        return format_waf_result(res);
    case DDWAF_ERR_INTERNAL:
        throw internal_error();
    case DDWAF_ERR_INVALID_OBJECT:
        throw invalid_object();
    case DDWAF_ERR_INVALID_ARGUMENT:
        throw invalid_argument();
    case DDWAF_OK:
        if (res.timeout) {
            throw timeout_error();
        }
        break;
    default:
        break;
    }

    return std::nullopt;
}

void instance::listener::get_meta_and_metrics(
    std::map<std::string_view, std::string> &meta,
    std::map<std::string_view, double> &metrics)
{
    meta[tag::event_rules_version] = ruleset_version_;
    metrics[tag::waf_duration] = total_runtime_;
}

instance::instance(parameter &rule,
    std::map<std::string_view, std::string> &meta,
    std::map<std::string_view, double> &metrics, std::uint64_t waf_timeout_us,
    std::string_view key_regex, std::string_view value_regex)
    : waf_timeout_{waf_timeout_us}
{
    const ddwaf_config config{
        {0, 0, 0}, {key_regex.data(), value_regex.data()}, nullptr};

    ddwaf_object diagnostics;
    handle_ = ddwaf_init(rule, &config, &diagnostics);

    extract_tags_and_metrics(
        parameter_view{diagnostics}, ruleset_version_, meta, metrics);
    meta[tag::waf_version] = ddwaf_get_version();

    ddwaf_object_free(&diagnostics);

    if (handle_ == nullptr) {
        throw invalid_object();
    }

    uint32_t size;
    const auto *addrs = ddwaf_required_addresses(handle_, &size);

    addresses_.clear();
    for (uint32_t i = 0; i < size; i++) { addresses_.emplace(addrs[i]); }
}

instance::instance(instance &&other) noexcept
    : handle_(other.handle_), waf_timeout_(other.waf_timeout_),
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

instance::listener::ptr instance::get_listener()
{
    return listener::ptr(new listener(
        ddwaf_context_init(handle_), waf_timeout_, ruleset_version_));
}

instance::instance(
    ddwaf_handle handle, std::chrono::microseconds timeout, std::string version)
    : handle_(handle), waf_timeout_(timeout),
      ruleset_version_(std::move(version))
{
    uint32_t size;
    const auto *addrs = ddwaf_required_addresses(handle_, &size);

    addresses_.clear();
    for (uint32_t i = 0; i < size; i++) { addresses_.emplace(addrs[i]); }
}

subscriber::ptr instance::update(parameter &rule,
    std::map<std::string_view, std::string> &meta,
    std::map<std::string_view, double> &metrics)
{
    ddwaf_object diagnostics;
    auto *new_handle = ddwaf_update(handle_, rule, &diagnostics);

    std::string version;
    extract_tags_and_metrics(
        parameter_view{diagnostics}, version, meta, metrics);
    meta[tag::waf_version] = ddwaf_get_version();
    if (version.empty()) {
        version = ruleset_version_;
    }

    ddwaf_object_free(&diagnostics);

    if (new_handle == nullptr) {
        throw invalid_object();
    }

    return subscriber::ptr(
        new instance(new_handle, waf_timeout_, std::move(version)));
}

instance::ptr instance::from_settings(const engine_settings &settings,
    const engine_ruleset &ruleset,
    std::map<std::string_view, std::string> &meta,
    std::map<std::string_view, double> &metrics)
{
    dds::parameter param = json_to_parameter(ruleset.get_document());
    return std::make_shared<instance>(param, meta, metrics,
        settings.waf_timeout_us, settings.obfuscator_key_regex,
        settings.obfuscator_value_regex);
}

instance::ptr instance::from_string(std::string_view rule,
    std::map<std::string_view, std::string> &meta,
    std::map<std::string_view, double> &metrics, std::uint64_t waf_timeout_us,
    std::string_view key_regex, std::string_view value_regex)
{
    engine_ruleset const ruleset{rule};
    dds::parameter param = json_to_parameter(ruleset.get_document());
    return std::make_shared<instance>(
        param, meta, metrics, waf_timeout_us, key_regex, value_regex);
}

} // namespace dds::waf
