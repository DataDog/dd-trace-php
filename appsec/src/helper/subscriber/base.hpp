// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../action.hpp"
#include "../network/proto.hpp"
#include "../parameter_view.hpp"
#include "../remote_config/changeset.hpp"
#include "../telemetry.hpp"
#include <memory>

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
            const network::request_exec_options &options) = 0;

        // NOLINTNEXTLINE(google-runtime-references)
        virtual void submit_metrics(
            telemetry::telemetry_submitter &msubmitter) = 0;
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
    virtual std::unique_ptr<subscriber> update(
        const remote_config::changeset &changeset,
        telemetry::telemetry_submitter &submit_metric) = 0;
};

} // namespace dds
