// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "engine.hpp"
#include "exception.hpp"
#include "metrics.hpp"
#include "remote_config/client_handler.hpp"
#include "sampler.hpp"
#include "service_config.hpp"
#include "service_identifier.hpp"
#include "std_logging.hpp"
#include "utils.hpp"
#include <chrono>
#include <memory>
#include <mutex>
#include <spdlog/spdlog.h>
#include <unordered_map>

namespace dds {

using namespace std::chrono_literals;

class service {
protected:
    class MetricsImpl : public metrics::TelemetrySubmitter {
        struct tel_metric {
            tel_metric(std::string_view name, double value, std::string tags)
                : name{name}, value{value}, tags{std::move(tags)}
            {}
            std::string_view name;
            double value;
            std::string tags;
        };

    public:
        MetricsImpl() = default;
        MetricsImpl(const MetricsImpl &) = delete;
        MetricsImpl &operator=(const MetricsImpl &) = delete;
        MetricsImpl(MetricsImpl &&) = delete;
        MetricsImpl &operator=(MetricsImpl &&) = delete;

        ~MetricsImpl() override = default;

        void submit_metric(std::string_view metric_name, double value,
            std::string tags) override
        {
            SPDLOG_TRACE("submit_metric: {} {} {}", metric_name, value, tags);
            const std::lock_guard<std::mutex> lock{pending_metrics_mutex_};
            pending_metrics_.emplace_back(metric_name, value, std::move(tags));
        }

        void submit_legacy_metric(std::string_view name, double value) override
        {
            SPDLOG_TRACE("submit_legacy_metric: {} {}", name, value);
            const std::lock_guard<std::mutex> lock{legacy_metrics_mutex_};
            legacy_metrics_[name] = value;
        }
        void submit_legacy_meta(
            std::string_view name, std::string value) override
        {
            SPDLOG_TRACE("submit_legacy_meta: {} {}", name, value);
            const std::lock_guard<std::mutex> lock{meta_mutex_};
            meta_[std::string{name}] = std::move(value);
        }

        // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
        void submit_legacy_meta_copy_key(
            std::string name, std::string value) override
        {
            const std::lock_guard<std::mutex> lock{meta_mutex_};
            meta_[std::move(name)] = std::move(value);
        }

        template <typename Func> void drain_metrics(Func &&func)
        {
            std::vector<tel_metric> metrics;
            {
                const std::lock_guard<std::mutex> lock(pending_metrics_mutex_);
                metrics.swap(pending_metrics_);
            }
            for (auto &metric : metrics) {
                std::invoke(std::forward<Func>(func), metric.name, metric.value,
                    std::move(metric.tags));
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

    private:
        std::vector<tel_metric> pending_metrics_;
        std::mutex pending_metrics_mutex_;
        std::map<std::string_view, double> legacy_metrics_;
        std::mutex legacy_metrics_mutex_;
        std::map<std::string, std::string> meta_;
        std::mutex meta_mutex_;
    };

    static std::shared_ptr<MetricsImpl> create_shared_metrics()
    {
        return std::make_shared<MetricsImpl>();
    }

    service(std::shared_ptr<engine> engine,
        std::shared_ptr<service_config> service_config,
        dds::remote_config::client_handler::ptr &&client_handler,
        std::shared_ptr<MetricsImpl> msubmitter,
        const schema_extraction_settings &schema_extraction_settings = {});

    template <typename... Args>
    static std::shared_ptr<service> create_shared(Args &&...args)
    {
        return std::shared_ptr<service>(
            new service(std::forward<Args>(args)...));
    }

public:
    using ptr = std::shared_ptr<service>;

    service(const service &) = delete;
    service &operator=(const service &) = delete;

    service(service &&) = delete;
    service &operator=(service &&) = delete;

    virtual ~service() = default;

    static service::ptr from_settings(service_identifier &&id,
        const dds::engine_settings &eng_settings,
        const remote_config::settings &rc_settings, bool dynamic_enablement);

    virtual void register_runtime_id(const std::string &id)
    {
        if (client_handler_) {
            client_handler_->register_runtime_id(id);
        }
    }

    virtual void unregister_runtime_id(const std::string &id)
    {
        if (client_handler_) {
            client_handler_->unregister_runtime_id(id);
        }
    }

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

    [[nodiscard]] std::shared_ptr<sampler> get_schema_sampler()
    {
        return schema_sampler_;
    }

    template <typename Func> void drain_metrics(Func &&func)
    {
        msubmitter_->drain_metrics(std::forward<Func>(func));
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
                "remote_config.requests_before_running"sv, 1, "");
        }
    }

protected:
    std::shared_ptr<engine> engine_{};
    std::shared_ptr<service_config> service_config_{};
    dds::remote_config::client_handler::ptr client_handler_{};
    std::shared_ptr<sampler> schema_sampler_;
    std::shared_ptr<MetricsImpl> msubmitter_;
};

} // namespace dds
