// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../engine_settings.hpp"
#include "../metrics.hpp"
#include "../parameter.hpp"
#include "../parameter_view.hpp"
#include <memory>
#include <optional>
#include <vector>

namespace dds {

class subscriber {
public:
    using ptr = std::shared_ptr<subscriber>;

    struct event {
        std::vector<std::string> data;
        std::unordered_set<std::string> actions;
    };

    class listener {
    public:
        using ptr = std::shared_ptr<listener>;

        listener() = default;
        listener(const listener &) = default;
        listener &operator=(const listener &) = delete;
        listener(listener &&) = default;
        listener &operator=(listener &&) = delete;

        virtual ~listener() = default;
        // NOLINTNEXTLINE(google-runtime-references)
        virtual std::optional<event> call(parameter_view &data) = 0;

        // NOLINTNEXTLINE(google-runtime-references)
        virtual void submit_metrics(metrics::TelemetrySubmitter& msubmitter) = 0;
    };

    subscriber() = default;
    virtual ~subscriber() = default;

    subscriber(const subscriber &) = delete;
    subscriber &operator=(const subscriber &) = delete;
    subscriber(subscriber &&) = delete;
    subscriber &operator=(subscriber &&) = delete;

    virtual std::string_view get_name() = 0;
    virtual std::unordered_set<std::string> get_subscriptions() = 0;
    virtual listener::ptr get_listener() = 0;
    virtual subscriber::ptr update(
        parameter &rule, metrics::TelemetrySubmitter& submit_metric) = 0;
};

} // namespace dds
