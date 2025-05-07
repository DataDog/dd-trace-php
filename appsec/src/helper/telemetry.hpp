#pragma once

#include <optional>
#include <spdlog/spdlog.h>
#include <string>
#include <string_view>

namespace dds::telemetry {

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

    // Mirror the CLogLevel enum from the FFI
    enum class log_level {
        Error = 1,
        Warn = 2,
        Debug = 3,
    };

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
    virtual void submit_log(log_level level, std::string identifier,
        std::string message, std::optional<std::string> stack_trace,
        std::optional<std::string> tags, bool is_sensitive) = 0;
};
inline telemetry_submitter::~telemetry_submitter() = default;
} // namespace dds::telemetry

template <>
struct fmt::formatter<dds::telemetry::telemetry_tags>
    : fmt::formatter<std::string_view> {

    auto format(
        const dds::telemetry::telemetry_tags tags, format_context &ctx) const
    {
        return formatter<std::string_view>::format(
            std::string_view{tags.data_}, ctx);
    }
};
template <>
struct fmt::formatter<dds::telemetry::telemetry_submitter::log_level>
    : fmt::formatter<std::string_view> {
    auto format(const dds::telemetry::telemetry_submitter::log_level level,
        format_context &ctx) const
    {
        std::string_view name = "unknown";
        switch (level) {
        case dds::telemetry::telemetry_submitter::log_level::Error:
            name = "Error";
            break;
        case dds::telemetry::telemetry_submitter::log_level::Warn:
            name = "Warn";

            break;
        case dds::telemetry::telemetry_submitter::log_level::Debug:
            name = "Debug";
            break;
        }
        return fmt::formatter<std::string_view>::format(name, ctx);
    }
};
