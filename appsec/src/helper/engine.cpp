// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <atomic>
#include <memory>
#include <spdlog/spdlog.h>

#include "engine.hpp"
#include "engine_settings.hpp"
#include "exception.hpp"
#include "json_helper.hpp"
#include "metrics.hpp"
#include "parameter_view.hpp"
#include "remote_config/changeset.hpp"
#include "remote_config/listeners/config_aggregators/asm_aggregator.hpp"
#include "std_logging.hpp"
#include "subscriber/waf.hpp"

namespace {
using dds::remote_config::asm_aggregator;
using dds::remote_config::changeset;

changeset build_changeset(const rapidjson::Value &doc)
{
    changeset changeset;
    // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-array-to-pointer-decay)
    if (doc.HasMember(asm_aggregator::ASM_ADDED)) {
        // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-array-to-pointer-decay)
        for (const auto &entry : doc[asm_aggregator::ASM_ADDED].GetObject()) {
            changeset.added.emplace(
                entry.name.GetString(), dds::json_to_parameter(entry.value));
        }
    }

    // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-array-to-pointer-decay)
    if (doc.HasMember(asm_aggregator::ASM_REMOVED)) {
        // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-array-to-pointer-decay)
        const auto &removed = doc[asm_aggregator::ASM_REMOVED];
        for (const auto &entry : removed.GetArray()) {
            changeset.removed.emplace(entry.GetString());
        }
    }

    return changeset;
}
} // namespace
namespace dds {

void engine::subscribe(std::unique_ptr<subscriber> sub)
{
    common_->subscribers.emplace_back(std::move(sub));
}

void engine::update(const rapidjson::Document &doc,
    telemetry::telemetry_submitter &submit_metric)
{
    std::vector<std::unique_ptr<subscriber>> new_subscribers;
    auto old_common =
        std::atomic_load_explicit(&common_, std::memory_order_acquire);
    new_subscribers.reserve(old_common->subscribers.size());
    changeset const changeset = build_changeset(doc);
    for (auto &sub : old_common->subscribers) {
        try {
            std::unique_ptr<subscriber> new_sub =
                sub->update(changeset, submit_metric);
            new_subscribers.emplace_back(std::move(new_sub));
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

std::optional<engine::result> engine::context::publish(
    parameter &&param, const std::string &rasp_rule)
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

    auto common =
        std::atomic_load_explicit(&common_, std::memory_order_acquire);
    for (auto &sub : common->subscribers) {
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
            listener->call(data, event_, rasp_rule);
        } catch (std::exception &e) {
            SPDLOG_ERROR("subscriber failed: {}", e.what());
        }
    }

    if (event_.actions.empty() && event_.data.empty()) {
        return std::nullopt;
    }

    const bool force_keep = event_.keep || limiter_.allow();
    dds::engine::result res{{}, std::move(event_.data), force_keep};
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

    return res;
}

void engine::context::get_metrics(telemetry::telemetry_submitter &msubmitter)
{
    for (const auto &[subscriber, listener] : listeners_) {
        listener->submit_metrics(msubmitter);
    }
}

std::unique_ptr<engine> engine::from_settings(
    const dds::engine_settings &eng_settings,
    telemetry::telemetry_submitter &msubmitter)
{
    auto &&rules_path = eng_settings.rules_file_or_default();
    auto ruleset = read_file(rules_path);

    rapidjson::Document doc;
    rapidjson::ParseResult const result =
        doc.Parse(ruleset.data(), ruleset.size());
    if ((result == nullptr) || !doc.IsObject()) {
        throw parsing_error("invalid json rule");
    }
    dds::parameter ruleset_param = json_to_parameter(doc);

    std::unique_ptr<engine> engine_ptr{
        engine::create(eng_settings.trace_rate_limit)};

    try {
        SPDLOG_DEBUG("Will load WAF rules from {}", rules_path);
        // may throw std::exception
        auto waf = waf::instance::from_settings(
            eng_settings, std::move(ruleset_param), msubmitter);
        engine_ptr->subscribe(std::move(waf));
    } catch (...) {
        DD_STDLOG(DD_STDLOG_WAF_INIT_FAILED, rules_path);
        throw;
    }

    return engine_ptr;
}

} // namespace dds
