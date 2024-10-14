// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <algorithm>
#include <memory>
#include <spdlog/spdlog.h>

#include "engine.hpp"
#include "engine_settings.hpp"
#include "exception.hpp"
#include "json_helper.hpp"
#include "parameter_view.hpp"
#include "std_logging.hpp"
#include "subscriber/waf.hpp"

namespace dds {

void engine::subscribe(std::unique_ptr<subscriber> sub)
{
    common_->subscribers.emplace_back(std::move(sub));
}

void engine::update(engine_ruleset &ruleset,
    std::map<std::string, std::string> &meta,
    std::map<std::string_view, double> &metrics)
{
    std::vector<std::unique_ptr<subscriber>> new_subscribers;
    new_subscribers.reserve(common_->subscribers.size());
    dds::parameter param = json_to_parameter(ruleset.get_document());
    for (auto &sub : common_->subscribers) {
        try {
            new_subscribers.emplace_back(sub->update(param, meta, metrics));
        } catch (const std::exception &e) {
            SPDLOG_WARN("Failed to update subscriber {}: {}", sub->get_name(),
                e.what());
            return; // no partial updates
        } catch (...) {
            SPDLOG_WARN("Failed to update subscriber {}: unknown reason",
                sub->get_name());
            return;
        }
    }

    auto new_common = std::make_shared<shared_state>(
        shared_state{std::move(new_subscribers)});
    std::atomic_store_explicit(&common_, new_common, std::memory_order_release);
}

std::optional<engine::result> engine::context::publish(parameter &&param)
{
    // Once the parameter reaches this function, it is guaranteed to be
    // owned by the engine.
    prev_published_params_.push_back(std::move(param));

    parameter_view data(prev_published_params_.back());
    if (!data.is_map()) {
        throw invalid_object(".", "not a map");
    }

    for (const auto &entry : data) {
        DD_STDLOG(DD_STDLOG_IG_DATA_PUSHED, entry.key());
    }

    event event_;

    for (auto &sub : common_->subscribers) {
        auto it = listeners_.find(sub.get());
        if (it == listeners_.end()) {
            auto listener = sub->get_listener();
            assert(listener.get() != nullptr);
            auto &&[iterator, inserted] =
                listeners_.emplace(sub.get(), std::move(listener));
            assert(inserted == true);
            it = iterator;
        }
        try {
            const auto &listener = it->second;
            listener->call(data, event_);
        } catch (std::exception &e) {
            SPDLOG_ERROR("subscriber failed: {}", e.what());
        }
    }

    if (event_.actions.empty() && event_.data.empty()) {
        return std::nullopt;
    }

    dds::engine::result res{{}, std::move(event_.data)};
    // Currently the only action the extension can perform is block
    if (event_.actions.empty()) {
        action record = {dds::action_type::record, {}};
        res.actions.emplace_back(std::move(record));
    }

    for (auto const &action : event_.actions) {
        dds::action new_action;
        new_action.type = action.type;
        new_action.parameters.insert(
            action.parameters.begin(), action.parameters.end());
        if (new_action.type != dds::action_type::invalid) {
            res.actions.push_back(new_action);
        }
    }

    res.force_keep = limiter_.allow();

    return res;
}

void engine::context::get_meta_and_metrics(
    std::map<std::string, std::string> &meta,
    std::map<std::string_view, double> &metrics)
{
    for (const auto &[subscriber, listener] : listeners_) {
        listener->get_meta_and_metrics(meta, metrics);
    }
}

std::unique_ptr<engine> engine::from_settings(
    const dds::engine_settings &eng_settings,
    std::map<std::string, std::string> &meta,
    std::map<std::string_view, double> &metrics)
{
    auto &&rules_path = eng_settings.rules_file_or_default();
    auto ruleset = engine_ruleset::from_path(rules_path);
    std::unique_ptr<engine> engine_ptr{
        engine::create(eng_settings.trace_rate_limit)};

    try {
        SPDLOG_DEBUG("Will load WAF rules from {}", rules_path);
        // may throw std::exception
        auto waf =
            waf::instance::from_settings(eng_settings, ruleset, meta, metrics);
        engine_ptr->subscribe(std::move(waf));
    } catch (...) {
        DD_STDLOG(DD_STDLOG_WAF_INIT_FAILED, rules_path);
        throw;
    }

    return engine_ptr;
}

} // namespace dds
