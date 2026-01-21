// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "engine.hpp"
#include "metrics.hpp"
#include "remote_config/client_handler.hpp"
#include "sampler.hpp"
#include "service_config.hpp"
#include "sidecar_settings.hpp"
#include "telemetry_settings.hpp"
#include <common.h> // components-rs/common.h
#include <memory>
#include <mutex>
#include <spdlog/spdlog.h>

namespace dds {

using namespace std::chrono_literals;

using sampler = timed_set<4096, 8192>; // NOLINT

class service {
protected:
    class metrics_impl : public telemetry::telemetry_submitter {
        struct tel_metric {
            tel_metric(std::string_view name, double value,
                telemetry::telemetry_tags tags)
                : name{name}, value{value}, tags{std::move(tags)}
            {}
            std::string_view name;
            double value;
            telemetry::telemetry_tags tags;
        };

        struct tel_log {
            telemetry::telemetry_submitter::log_level level;
            std::string identifier;
            std::string message;
            std::optional<std::string> stack_trace;
            std::optional<std::string> tags;
            bool is_sensitive;
        };

        static constexpr std::size_t MAX_PENDING_LOGS = 100;

    public:
        metrics_impl() = default;
        metrics_impl(const metrics_impl &) = delete;
        metrics_impl &operator=(const metrics_impl &) = delete;
        metrics_impl(metrics_impl &&) = delete;
        metrics_impl &operator=(metrics_impl &&) = delete;

        ~metrics_impl() override = default;

        void submit_metric(std::string_view metric_name, double value,
            telemetry::telemetry_tags tags) override
        {
            SPDLOG_TRACE("submit_metric: {} {} {}", metric_name, value, tags);
            const std::lock_guard<std::mutex> lock{pending_metrics_mutex_};
            pending_metrics_.emplace_back(metric_name, value, std::move(tags));
        }

        void submit_span_metric(std::string_view name, double value) override
        {
            SPDLOG_TRACE("submit_span_metric: {} {}", name, value);
            const std::lock_guard<std::mutex> lock{legacy_metrics_mutex_};
            legacy_metrics_[name] = value;
        }
        void submit_span_meta(std::string_view name, std::string value) override
        {
            SPDLOG_TRACE("submit_span_meta: {} {}", name, value);
            const std::lock_guard<std::mutex> lock{meta_mutex_};
            meta_[std::string{name}] = std::move(value);
        }

        // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
        void submit_span_meta_copy_key(
            std::string name, std::string value) override
        {
            SPDLOG_TRACE("submit_span_meta_copy_key: {} {}", name, value);
            const std::lock_guard<std::mutex> lock{meta_mutex_};
            meta_[std::move(name)] = std::move(value);
        }

        void submit_log(telemetry::telemetry_submitter::log_level level,
            std::string identifier, std::string message,
            std::optional<std::string> stack_trace,
            std::optional<std::string> tags, bool is_sensitive) override
        {
            if (pending_logs_.size() >= MAX_PENDING_LOGS) {
                SPDLOG_WARN("Pending logs queue is full, dropping log");
                return;
            }

            SPDLOG_TRACE("submit_log [{}][{}]: {}", level, identifier, message);
            const std::lock_guard<std::mutex> lock{pending_logs_mutex_};
            pending_logs_.emplace_back(
                tel_log{level, std::move(identifier), std::move(message),
                    std::move(stack_trace), std::move(tags), is_sensitive});
        }

    private:
        friend class service;

        void drain_metrics(const sidecar_settings &sc_settings,
            const telemetry_settings &telemetry_settings)
        {
            std::vector<tel_metric> metrics;
            {
                const std::lock_guard<std::mutex> lock(pending_metrics_mutex_);
                metrics.swap(pending_metrics_);
            }
            for (auto &metric : metrics) {
                submit_metric_ffi(sc_settings, telemetry_settings, metric.name,
                    metric.value, metric.tags.consume());
            }
        }

        void drain_logs(const sidecar_settings &sc_settings,
            const telemetry_settings &telemetry_settings)
        {
            std::vector<tel_log> logs;
            {
                const std::lock_guard<std::mutex> lock(pending_logs_mutex_);
                logs.swap(pending_logs_);
            }
            for (auto &log : logs) {
                submit_log(sc_settings, telemetry_settings, log);
            }
        }

        std::map<std::string_view, double> drain_legacy_metrics()
        {
            const std::lock_guard<std::mutex> lock{legacy_metrics_mutex_};
            return std::move(legacy_metrics_);
        }

        std::map<std::string, std::string> drain_legacy_meta()
        {
            const std::lock_guard<std::mutex> lock{meta_mutex_};
            return std::move(meta_);
        }

        static void submit_log(const sidecar_settings &sc_settings,
            const telemetry_settings &telemetry_settings, const tel_log &log);

        static void register_metric_ffi(const sidecar_settings &sc_settings,
            const telemetry_settings &telemetry_settings, std::string_view name,
            ddog_MetricType type);

        static void submit_metric_ffi(const sidecar_settings &sc_settings,
            const telemetry_settings &telemetry_settings, std::string_view name,
            double value, std::optional<std::string> tags);

        std::vector<tel_metric> pending_metrics_;
        std::mutex pending_metrics_mutex_;
        std::vector<tel_log> pending_logs_;
        std::mutex pending_logs_mutex_;
        std::map<std::string_view, double> legacy_metrics_;
        std::mutex legacy_metrics_mutex_;
        std::map<std::string, std::string> meta_;
        std::mutex meta_mutex_;
    };

    // TODO: remove this. For testing only
    static std::shared_ptr<metrics_impl> create_shared_metrics()
    {
        return std::make_shared<metrics_impl>();
    }

    service(std::shared_ptr<engine> engine,
        std::shared_ptr<service_config> service_config,
        std::unique_ptr<dds::remote_config::client_handler> client_handler,
        std::shared_ptr<metrics_impl> msubmitter, std::string rc_path,
        telemetry_settings telemetry_settings,
        const schema_extraction_settings &schema_extraction_settings = {});

    template <typename... Args>
    static std::shared_ptr<service> create_shared(Args &&...args)
    {
        return std::shared_ptr<service>(
            new service(std::forward<Args>(args)...));
    }

    void register_known_metrics(const sidecar_settings &sc_settings,
        const telemetry_settings &telemetry_settings);

public:
    service(const service &) = delete;
    service &operator=(const service &) = delete;

    service(service &&) = delete;
    service &operator=(service &&) = delete;

    virtual ~service()
    {
        SPDLOG_TRACE("service {} destroyed", static_cast<void *>(this));
    }

    static std::shared_ptr<service> from_settings(
        const dds::engine_settings &eng_settings,
        const remote_config::settings &rc_settings,
        telemetry_settings telemetry_settings);

    static void resolve_symbols();

    [[nodiscard]] std::shared_ptr<engine> get_engine() const
    {
        // TODO make access atomic?
        return engine_;
    }

    [[nodiscard]] std::shared_ptr<service_config> get_service_config() const
    {
        // TODO make access atomic?
        return service_config_;
    }

    [[nodiscard]] bool schema_extraction_enabled() const
    {
        return schema_extraction_enabled_;
    }

    [[nodiscard]] std::optional<sampler> &get_schema_sampler()
    {
        return schema_sampler_;
    }

    [[nodiscard]] bool is_remote_config_shmem_path(std::string_view path)
    {
        if (rc_path_ != path) {
            SPDLOG_DEBUG(
                "remote config path changed: {} -> {}", rc_path_, path);
            return false;
        }
        return true;
    }

    [[nodiscard]] bool is_telemetry_settings(
        const telemetry_settings &telemetry_settings) const
    {
        if (telemetry_settings_ != telemetry_settings) {
            SPDLOG_DEBUG("telemetry_settings changed: {} -> {}",
                telemetry_settings_, telemetry_settings);
            return false;
        }
        return true;
    }

    void notify_of_rc_updates() { client_handler_->poll(); }

    void submit_request_metric(std::string_view metric_name, double value,
        telemetry::telemetry_tags tags)
    {
        msubmitter_->submit_metric(metric_name, value, std::move(tags));
    }

    void drain_metrics(const sidecar_settings &sc_settings)
    {
        register_known_metrics(sc_settings, telemetry_settings_);

        msubmitter_->drain_metrics(sc_settings, telemetry_settings_);
    }

    void drain_logs(const sidecar_settings &sc_settings)
    {
        msubmitter_->drain_logs(sc_settings, telemetry_settings_);

        register_known_metrics(sc_settings, telemetry_settings_);
        handle_worker_count_metrics(sc_settings);
    }

    [[nodiscard]] std::map<std::string_view, double> drain_legacy_metrics()
    {
        return msubmitter_->drain_legacy_metrics();
    }

    [[nodiscard]] std::map<std::string, std::string> drain_legacy_meta()
    {
        return msubmitter_->drain_legacy_meta();
    }

    // to be called just before the submitting data to the engine for the first
    // time in the request
    void before_first_publish() const
    {
        if (client_handler_ && !client_handler_->has_applied_rc()) {
            msubmitter_->submit_metric(
                "remote_config.requests_before_running"sv, 1, {});
        }
    }

    void increment_num_workers()
    {
        auto cur = num_workers_.load(std::memory_order_relaxed);
        while (true) {
            auto new_v = num_workers_t{
                .latest_count_sent = false, .count = cur.count + 1};
            if (num_workers_.compare_exchange_weak(
                    cur, new_v, std::memory_order_relaxed)) {
                break;
            }
        }
    }
    void decrement_num_workers()
    {
        auto cur = num_workers_.load(std::memory_order_relaxed);
        if (cur.count == 0) {
            SPDLOG_WARN("Attempted to decrement num_workers_ below 0");
            return;
        }
        while (true) {
            auto new_v =
                num_workers_t{.latest_count_sent = cur.latest_count_sent,
                    .count = cur.count - 1};
            if (num_workers_.compare_exchange_weak(
                    cur, new_v, std::memory_order_relaxed)) {
                break;
            }
        }
    }

protected:
    std::shared_ptr<engine> engine_;
    std::shared_ptr<service_config> service_config_;
    std::unique_ptr<dds::remote_config::client_handler> client_handler_;
    bool schema_extraction_enabled_;
    std::optional<sampler> schema_sampler_;
    std::string rc_path_;
    telemetry_settings telemetry_settings_;
    std::atomic<std::chrono::time_point<std::chrono::steady_clock>>
        metrics_registered_at_;
    std::shared_ptr<metrics_impl> msubmitter_;

    struct num_workers_t {
        bool latest_count_sent : 1;
        uint64_t count : 63;
        constexpr bool operator==(const num_workers_t &other) const
        {
            return count == other.count &&
                   latest_count_sent == other.latest_count_sent;
        }
    };
    // required for value-initialization in default constructor of
    // std::atomic<num_workers_t> in C++20
    static_assert(std::is_default_constructible_v<num_workers_t>);
    std::atomic<num_workers_t> num_workers_;
    void handle_worker_count_metrics(const sidecar_settings &sc_settings);
};

} // namespace dds

template <> struct fmt::formatter<ddog_MetricType> {
    // NOLINTNEXTLINE
    constexpr auto parse(fmt::format_parse_context &ctx) { return ctx.begin(); }
    template <typename FormatContext>
    auto format(ddog_MetricType type, FormatContext &ctx) const
        -> decltype(ctx.out())
    {
        switch (type) {
        case DDOG_METRIC_TYPE_GAUGE:
            return fmt::format_to(ctx.out(), "GAUGE");
        case DDOG_METRIC_TYPE_COUNT:
            return fmt::format_to(ctx.out(), "COUNT");
        case DDOG_METRIC_TYPE_DISTRIBUTION:
            return fmt::format_to(ctx.out(), "DISTRIBUTION");
        }
        return fmt::format_to(ctx.out(), "UNKNOWN");
    }
};
