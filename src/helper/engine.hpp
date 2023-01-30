// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "config.hpp"
#include "engine_settings.hpp"
#include "parameter.hpp"
#include "rate_limit.hpp"
#include "subscriber/base.hpp"
#include <map>
#include <memory>
#include <rapidjson/document.h>
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
    using ptr = std::shared_ptr<engine>;
    using subscription_map =
        std::map<std::string_view, std::vector<subscriber::ptr>>;

    enum class action_type : uint8_t { record = 1, redirect = 2, block = 3 };

    struct action {
        action_type type;
        std::unordered_map<std::string, std::string> parameters;
    };

    using action_map = std::unordered_map<std::string /*id*/, action>;

    struct result {
        action_type type;
        std::unordered_map<std::string, std::string> parameters;
        std::vector<std::string> events;
    };

    // Assuming here the callers are nice enough that context doesn't live
    // beyond the engine. This could be enforced by having the context
    // store a shared_ptr to the engine
    class context {
    public:
        explicit context(engine &engine)
            : subscriptions_(engine.subscriptions_), limiter_(engine.limiter_),
              actions_(engine.actions_)
        {}
        context(const context &) = delete;
        context &operator=(const context &) = delete;
        context(context &&) = delete;
        context &operator=(context &&) = delete;
        ~context() = default;

        std::optional<result> publish(parameter &&param);
        // NOLINTNEXTLINE(google-runtime-references)
        void get_meta_and_metrics(std::map<std::string_view, std::string> &meta,
            std::map<std::string_view, double> &metrics);

    protected:
        std::vector<parameter> prev_published_params_;
        std::map<subscriber::ptr, subscriber::listener::ptr> listeners_;
        const subscription_map &subscriptions_;
        rate_limiter &limiter_;
        const action_map &actions_;
    };

    static engine::ptr from_settings(const dds::engine_settings &eng_settings,
        std::map<std::string_view, std::string> &meta,
        std::map<std::string_view, double> &metrics);

    static auto create(
        uint32_t trace_rate_limit = engine_settings::default_trace_rate_limit,
        action_map actions = default_actions)
    {
        return std::shared_ptr<engine>(
            new engine(trace_rate_limit, std::move(actions)));
    }

    context get_context() { return context{*this}; }
    void subscribe(const subscriber::ptr &sub);

    void update_rule_data(parameter_view &data);

    // Only exposed for testing purposes
    template <typename T,
        typename = std::enable_if_t<std::disjunction_v<
            std::is_same<rapidjson::Document,
                std::remove_cv_t<std::decay_t<T>>>,
            std::is_same<rapidjson::Value, std::remove_cv_t<std::decay_t<T>>>>>>
    static action_map parse_actions(
        const T &doc, const action_map &default_actions);

protected:
    explicit engine(uint32_t trace_rate_limit, action_map &&actions = {})
        : limiter_(trace_rate_limit), actions_(std::move(actions))
    {}

    static const action_map default_actions;

    std::vector<subscriber::ptr> subscribers_;
    subscription_map subscriptions_;
    rate_limiter limiter_;
    action_map actions_;
};

} // namespace dds
