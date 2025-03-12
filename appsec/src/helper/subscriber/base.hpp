// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../action.hpp"
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
    class listener {
    public:
        listener() = default;
        listener(const listener &) = default;
        listener &operator=(const listener &) = delete;
        listener(listener &&) = default;
        listener &operator=(listener &&) = delete;

        virtual ~listener() = default;
        // NOLINTNEXTLINE(google-runtime-references)
        virtual void call(parameter_view &data, event &event,
            const std::string &rasp_rule = "") = 0;

        // NOLINTNEXTLINE(google-runtime-references)
        virtual void submit_metrics(
            metrics::telemetry_submitter &msubmitter) = 0;
    };
    struct changeset {
        std::unordered_map<std::string, parameter> added;
        std::unordered_set<std::string> removed;

        std::optional<std::pair<std::string, parameter>> added_asm_dd;
        std::optional<std::string> removed_asm_dd;
    };

    subscriber() = default;
    virtual ~subscriber() = default;

    subscriber(const subscriber &) = delete;
    subscriber &operator=(const subscriber &) = delete;
    subscriber(subscriber &&) = delete;
    subscriber &operator=(subscriber &&) = delete;

    virtual std::string_view get_name() = 0;
    virtual std::unordered_set<std::string> get_subscriptions() = 0;
    virtual std::unique_ptr<listener> get_listener() = 0;
    virtual std::unique_ptr<subscriber> update(const changeset &changeset,
        metrics::telemetry_submitter &submit_metric) = 0;
};

} // namespace dds
