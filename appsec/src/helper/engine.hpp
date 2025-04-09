// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "action.hpp"
#include "engine_settings.hpp"
#include "metrics.hpp"
#include "parameter.hpp"
#include "rate_limit.hpp"
#include "subscriber/base.hpp"
#include <atomic>
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
class engine {
public:
    using action_map = std::unordered_map<std::string /*id*/, action>;

    struct result {
        std::vector<dds::action> actions;
        std::vector<std::string> events;
        bool force_keep;
    };

protected:
    struct shared_state {
        std::vector<std::unique_ptr<subscriber>> subscribers;
    };

public:
    // Assuming here the callers are nice enough that context doesn't live
    // beyond the engine. This could be enforced by having the context
    // store a shared_ptr to the engine
    class context {
    public:
        explicit context(engine &engine)
            : common_{std::atomic_load_explicit(
                  &engine.common_, std::memory_order_acquire)},
              limiter_{engine.limiter_}
        {}
        context(const context &) = delete;
        context &operator=(const context &) = delete;
        context(context &&) = delete;
        context &operator=(context &&) = delete;
        ~context() = default;

        std::optional<result> publish(
            parameter &&param, const std::string &rasp_rule = "");
        // NOLINTNEXTLINE(google-runtime-references)
        void get_metrics(metrics::telemetry_submitter &msubmitter);

    protected:
        std::shared_ptr<shared_state> common_;
        std::map<subscriber *, const std::unique_ptr<subscriber::listener>>
            listeners_;
        std::vector<parameter> prev_published_params_;
        rate_limiter<dds::timer> &
            limiter_; // NOLINT(cppcoreguidelines-avoid-const-or-ref-data-members)
    };

    engine(const engine &) = delete;
    engine &operator=(const engine &) = delete;
    engine(engine &&) = delete;
    engine &operator=(engine &&) = delete;
    virtual ~engine() = default;

    static std::unique_ptr<engine> from_settings(
        const dds::engine_settings &eng_settings,
        metrics::telemetry_submitter &msubmitter);

    static auto create(
        uint32_t trace_rate_limit = engine_settings::default_trace_rate_limit)
    {
        return std::unique_ptr<engine>(new engine(trace_rate_limit));
    }

    // Not thread-safe, should only be called after construction
    void subscribe(std::unique_ptr<subscriber> sub);

    context get_context() { return context{*this}; }

    // Should not be called concurrently but safely publishes changes to common_
    // the rc client has a lock that ensures this
    virtual void update(const rapidjson::Document &doc,
        metrics::telemetry_submitter &submit_metric);

protected:
    explicit engine(uint32_t trace_rate_limit)
        : common_(new shared_state{{}}), limiter_(trace_rate_limit)
    {}

    // in practice: the current ddwaf_handle, swapped in update
    // should use only atomic operations (pre-c++20
    // std::atomic<std::shared_ptr>)
    std::shared_ptr<shared_state> common_;
    std::shared_ptr<metrics::telemetry_submitter> msubmitter_;
    rate_limiter<dds::timer> limiter_;
};

} // namespace dds
