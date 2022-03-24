// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "client_settings.hpp"
#include "config.hpp"
#include "parameter.hpp"
#include "rate_limit.hpp"
#include "result.hpp"
#include "subscriber/base.hpp"
#include <map>
#include <memory>
#include <spdlog/fmt/ostr.h>
#include <string>
#include <vector>

namespace dds {

/**
 * Semantics:
 *    - engine: pub/sub broker, provides subscription framework.
 *    - engine::context: request-bound broker, provides publishing framework.
 *    - subscriber: data consumer, defines its required topics and immutable
 *                  state.
 *    - subscriber::listener: request-bound data consumer, consumes the data and
 *                            provides the mutable state.
 *    - address: addresses to which a subscriber subscribes.
 *    - subscription: the mapping between an address and a subscriber.
 **/
class engine : std::enable_shared_from_this<engine> {
public:
    using subscription_map =
        std::map<std::string_view, std::vector<subscriber::ptr>>;

    // Assuming here the callers are nice enough that context doesn't live
    // beyond the engine. This could be enforced by having the context
    // store a shared_ptr to the engine
    class context {
    public:
        explicit context(engine &engine)
            : subscriptions_(engine.subscriptions_), limiter_(engine.limiter_)
        {}
        context(const context &) = delete;
        context &operator=(const context &) = delete;
        context(context &&) = delete;
        context &operator=(context &&) = delete;
        ~context() = default;

        result publish(parameter &&param);

    protected:
        std::vector<parameter> prev_published_params_;
        std::map<subscriber::ptr, subscriber::listener::ptr> listeners_;
        const subscription_map &subscriptions_;
        rate_limiter &limiter_;
    };

    static auto create(
        uint32_t trace_rate_limit = client_settings::default_trace_rate_limit)
    {
        return std::shared_ptr<engine>(new engine(trace_rate_limit));
    }

    context get_context() { return context{*this}; }
    void subscribe(const subscriber::ptr &sub);

protected:
    explicit engine(uint32_t trace_rate_limit) : limiter_(trace_rate_limit) {}

    subscription_map subscriptions_;
    rate_limiter limiter_;
};

} // namespace dds
