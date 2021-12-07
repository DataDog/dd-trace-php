// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include "../std_logging.hpp"
#include "ddwaf.h"
#include <chrono>
#include <cstdlib>
#include <fstream>
#include <ios>
#include <limits>
#include <memory>
#include <rapidjson/document.h>
#include <rapidjson/error/en.h>
#include <rapidjson/writer.h>
#include <stdexcept>
#include <string_view>

#include "../json_helper.hpp"
#include "../result.hpp"
#include "waf.hpp"

namespace {

// TODO: actually, we should limit the recursion
// NOLINTNEXTLINE(misc-no-recursion)
template <typename T> void json_to_object(ddwaf_object *object, T &doc) {
    switch (doc.GetType()) {
    case rapidjson::kFalseType:
        ddwaf_object_stringl(object, "false", sizeof("false") - 1);
        break;
    case rapidjson::kTrueType:
        ddwaf_object_stringl(object, "true", sizeof("true") - 1);
        break;
    case rapidjson::kObjectType: {
        ddwaf_object_map(object);
        for (auto &kv : doc.GetObject()) {
            ddwaf_object element;
            json_to_object(&element, kv.value);

            std::string_view key = kv.name.GetString();
            ddwaf_object_map_addl(object, key.data(), key.length(), &element);
        }
        break;
    }
    case rapidjson::kArrayType: {
        ddwaf_object_array(object);
        for (auto &v : doc.GetArray()) {
            ddwaf_object element;
            json_to_object(&element, v);

            ddwaf_object_array_add(object, &element);
        }
        break;
    }
    case rapidjson::kStringType: {
        std::string_view str = doc.GetString();
        ddwaf_object_stringl(object, str.data(), str.size());
        break;
    }
    case rapidjson::kNumberType: {
        if (doc.IsInt64()) {
            ddwaf_object_signed(object, doc.GetInt64());
        } else if (doc.IsUint64()) {
            ddwaf_object_unsigned(object, doc.GetUint64());
        }
        break;
    }
    case rapidjson::kNullType:
    default:
        ddwaf_object_invalid(object);
        break;
    }
}

std::string read_rule_file(std::string_view filename) {
    std::ifstream rule_file(filename.data(), std::ios::in);
    if (!rule_file) {
        throw std::system_error(errno, std::generic_category());
    }

    // Create a buffer equal to the file size
    rule_file.seekg(0, std::ios::end);
    std::string buffer(rule_file.tellg(), '\0');
    buffer.resize(rule_file.tellg());
    rule_file.seekg(0, std::ios::beg);

    auto buffer_size = buffer.size();
    if (buffer_size > static_cast<typeof(buffer_size)>(
                          std::numeric_limits<std::streamsize>::max())) {
        throw std::runtime_error{"rule file is too large"};
    }

    rule_file.read(&buffer[0], static_cast<std::streamsize>(buffer.size()));
    buffer.resize(rule_file.gcount());
    rule_file.close();
    return buffer;
}

dds::result format_waf_result(dds::result::code code, std::string_view json) {
    dds::result res{code};
    rapidjson::Document doc;
    rapidjson::ParseResult status = doc.Parse(json.data(), json.size());
    if (status == nullptr) {
        SPDLOG_ERROR("failed to parse WAF output at {}: {}",
            rapidjson::GetParseError_En(status.Code()), status.Offset());
        return res;
    }

    if (doc.GetType() != rapidjson::kArrayType) {
        // perhaps throw something?
        SPDLOG_ERROR("unexpected WAF result type {}, expected array", doc.GetType());
        return res;
    }

    for (auto &v : doc.GetArray()) {
        dds::string_buffer buffer;
        rapidjson::Writer<decltype(buffer)> writer(buffer);
        v.Accept(writer);
        res.data.emplace_back(std::move(buffer.get_string_ref()));
    }

    return res;
}

DDWAF_LOG_LEVEL spdlog_level_to_ddwaf(spdlog::level::level_enum level)
{
    switch(level) {
    case spdlog::level::trace:
        return DDWAF_LOG_TRACE;
    case spdlog::level::debug:
        return DDWAF_LOG_DEBUG;
    case spdlog::level::info:
        return DDWAF_LOG_INFO;
    case spdlog::level::warn:
        return DDWAF_LOG_WARN;
    case spdlog::level::err: [[fallthrough]];
    case spdlog::level::critical:
        return DDWAF_LOG_ERROR;
    case spdlog::level::off: [[fallthrough]];
    default:
        break;
    }
    return DDWAF_LOG_OFF;
}

void log_cb(DDWAF_LOG_LEVEL level, const char* function, const char* file,
    unsigned line, const char* message, uint64_t message_len)
{
    auto new_level = spdlog::level::off;
    switch(level) {
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
    case DDWAF_LOG_OFF: [[fallthrough]];
    default:
        break;
    }

    spdlog::default_logger()->log(
        spdlog::source_loc{file, static_cast<int>(line), function},
        new_level, std::string_view(message, message_len));
}

} // namespace

namespace dds::waf {

void initialise_logging(spdlog::level::level_enum level)
{
    ddwaf_set_log_cb(log_cb, spdlog_level_to_ddwaf(level));
}

instance::listener::listener(ddwaf_context ctx) : handle_(ctx) {}

instance::listener::listener(instance::listener &&other) noexcept
    : handle_(other.handle_)
{
    other.handle_ = nullptr;
}

instance::listener &instance::listener::operator=(listener &&other) noexcept
{
    handle_ = other.handle_;
    other.handle_ = nullptr;
    return *this;
}

instance::listener::~listener() {
    if (handle_ != nullptr) {
        ddwaf_context_destroy(handle_);
    }
}

dds::result instance::listener::call(dds::parameter &data, unsigned timeout) {
    DD_STDLOG(DD_STDLOG_BEFORE_WAF);

    ddwaf_result res;
    DDWAF_RET_CODE code;
    auto run_waf = [&]() {
        code = ddwaf_run(handle_, data.ptr(), &res, timeout);
    };

    if (spdlog::should_log(spdlog::level::debug)) {
        DD_STDLOG(DD_STDLOG_CALLING_WAF, data.debug_str());
        auto start = std::chrono::steady_clock::now();

        run_waf();

        auto elapsed = std::chrono::steady_clock::now() - start;
        DD_STDLOG(DD_STDLOG_AFTER_WAF,
            std::chrono::duration_cast<
                std::chrono::duration<double, std::milli>>(elapsed)
                .count());

        DD_STDLOG(DD_STDLOG_RESULT_WAF, res.data ? res.data : "(no data)");
    } else {
        run_waf();
    }

    // Free result on exception/return
    std::unique_ptr<ddwaf_result, decltype(&ddwaf_result_free)> scope(
        &res, ddwaf_result_free);

    switch (code) {
    case DDWAF_BLOCK:
        return format_waf_result(dds::result::code::block, res.data);
    case DDWAF_MONITOR:
        return format_waf_result(dds::result::code::record, res.data);
    case DDWAF_ERR_INTERNAL:
        throw internal_error();
    case DDWAF_ERR_INVALID_OBJECT:
        throw invalid_object();
    case DDWAF_ERR_INVALID_ARGUMENT:
        throw invalid_argument();
    case DDWAF_GOOD:
        if (res.timeout) {
            throw timeout_error();
        }
        break;
    default:
        break;
    }

    return dds::result{dds::result::code::ok};
}

instance::instance(parameter &rule) : handle_(ddwaf_init(rule.ptr(), nullptr))
{
    rule.free();
    if (handle_ == nullptr) {
        throw invalid_object();
    }
}

instance::instance(instance &&other) noexcept : handle_(other.handle_)
{
    other.handle_ = nullptr;
}

instance &instance::operator=(instance &&other) noexcept
{
    handle_ = other.handle_;
    other.handle_ = nullptr;

    return *this;
}

instance::~instance() {
    if (handle_ != nullptr) {
        ddwaf_destroy(handle_);
    }
}

instance::listener::ptr instance::get_listener()
{
    return listener::ptr(new listener(ddwaf_context_init(handle_, nullptr)));
}

std::vector<std::string_view> instance::get_subscriptions() {
    uint32_t size;
    const auto *addrs = ddwaf_required_addresses(handle_, &size);

    std::vector<std::string_view> output(size);
    for (uint32_t i = 0; i < size; i++) { output.emplace_back(addrs[i]); }
    return output;
}

instance::ptr instance::from_file(std::string_view rule_file) {
    dds::parameter param = parse_file(rule_file);
    return std::make_shared<instance>(param);
}

instance::ptr instance::from_string(std::string_view rule) {
    dds::parameter param = parse_string(rule);
    return std::make_shared<instance>(param);
}

parameter parse_string(std::string_view config)
{
    rapidjson::Document document;
    rapidjson::ParseResult result = document.Parse(config.data());
    if ((result == nullptr) || !document.IsObject()) {
        throw parsing_error("invalid json rule");
    }

    parameter obj;
    json_to_object(obj.ptr(), document);
    return obj;
}

parameter parse_file(std::string_view filename) {
    auto json = read_rule_file(filename);
    return parse_string(json);
}

} // namespace dds::waf
