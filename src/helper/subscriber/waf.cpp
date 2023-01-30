// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "../std_logging.hpp"
#include "ddwaf.h"
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

namespace {

dds::subscriber::event format_waf_result(ddwaf_result &res)
{
    dds::subscriber::event output;
    for (unsigned i = 0; i < res.actions.size; i++) {
        char *value = res.actions.array[i];
        if (value == nullptr) {
            continue;
        }
        output.actions.emplace(value);
    }

    const std::string_view json = res.data;

    rapidjson::Document doc;
    const rapidjson::ParseResult status = doc.Parse(json.data(), json.size());
    if (status == nullptr) {
        SPDLOG_ERROR("failed to parse WAF output at {}: {}",
            rapidjson::GetParseError_En(status.Code()), status.Offset());
        return output;
    }

    if (doc.GetType() != rapidjson::kArrayType) {
        // perhaps throw something?
        SPDLOG_ERROR(
            "unexpected WAF result type {}, expected array", doc.GetType());
        return output;
    }

    for (auto &v : doc.GetArray()) {
        dds::string_buffer buffer;
        rapidjson::Writer<decltype(buffer)> writer(buffer);
        v.Accept(writer);
        output.data.emplace_back(std::move(buffer.get_string_ref()));
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

} // namespace

namespace dds::waf {

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
        auto start = std::chrono::steady_clock::now();

        run_waf();

        auto elapsed = std::chrono::steady_clock::now() - start;
        DD_STDLOG(DD_STDLOG_AFTER_WAF, res.data ? res.data : "(no data)",
            std::chrono::duration_cast<
                std::chrono::duration<double, std::milli>>(elapsed)
                .count());
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
    ddwaf_ruleset_info info;
    const ddwaf_config config{
        {0, 0, 0}, {key_regex.data(), value_regex.data()}, nullptr};

    handle_ = ddwaf_init(rule, &config, &info);

    metrics[tag::event_rules_loaded] = info.loaded;
    metrics[tag::event_rules_failed] = info.failed;
    meta[tag::event_rules_errors] =
        parameter_to_json(dds::parameter_view(info.errors));
    if (info.version != nullptr) {
        ruleset_version_ = info.version;
    }

    meta[tag::waf_version] = ddwaf_get_version();

    ddwaf_ruleset_info_free(&info);

    if (handle_ == nullptr) {
        throw invalid_object();
    }
}

instance::instance(instance &&other) noexcept
    : handle_{other.handle_}, waf_timeout_{other.waf_timeout_}
{
    other.handle_ = nullptr;
    other.waf_timeout_ = {};
}

instance &instance::operator=(instance &&other) noexcept
{
    handle_ = other.handle_;
    other.handle_ = nullptr;

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

bool instance::update_rule_data(parameter_view &data)
{
    return ddwaf_update_rule_data(handle_, data) == DDWAF_OK;
}

std::vector<std::string_view> instance::get_subscriptions()
{
    uint32_t size;
    const auto *addrs = ddwaf_required_addresses(handle_, &size);

    std::vector<std::string_view> output(size);
    for (uint32_t i = 0; i < size; i++) { output.emplace_back(addrs[i]); }
    return output;
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
    engine_ruleset ruleset{rule};
    dds::parameter param = json_to_parameter(ruleset.get_document());
    return std::make_shared<instance>(
        param, meta, metrics, waf_timeout_us, key_regex, value_regex);
}

} // namespace dds::waf
