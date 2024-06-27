// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <algorithm>
#include <rapidjson/rapidjson.h>
#include <set>
#include <spdlog/fmt/ostr.h>
#include <spdlog/spdlog.h>

#include "engine.hpp"
#include "engine_settings.hpp"
#include "exception.hpp"
#include "json_helper.hpp"
#include "metrics.hpp"
#include "parameter_view.hpp"
#include "std_logging.hpp"
#include "subscriber/waf.hpp"

namespace dds {

void engine::subscribe(const subscriber::ptr &sub)
{
    auto common = std::atomic_load(&common_);
    common->subscribers.emplace_back(sub);
}

void engine::update(
    engine_ruleset &ruleset, metrics::TelemetrySubmitter &submit_metric)
{
    auto new_actions =
        parse_actions(ruleset.get_document(), engine::default_actions);
    if (new_actions.empty()) {
        new_actions = common_->actions;
    }

    std::vector<subscriber::ptr> new_subscribers;
    new_subscribers.reserve(common_->subscribers.size());
    dds::parameter param = json_to_parameter(ruleset.get_document());
    for (auto &sub : common_->subscribers) {
        try {
            new_subscribers.emplace_back(sub->update(param, submit_metric));
        } catch (const std::exception &e) {
            SPDLOG_WARN("Failed to update subscriber {}: {}", sub->get_name(),
                e.what());
            new_subscribers.emplace_back(sub);
        } catch (...) {
            SPDLOG_WARN("Failed to update subscriber {}: unknown reason",
                sub->get_name());
            new_subscribers.emplace_back(sub);
        }
    }

    std::shared_ptr<shared_state> const new_common(
        new shared_state{std::move(new_subscribers), std::move(new_actions)});

    std::atomic_store(&common_, new_common);
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

    std::vector<std::string> event_data;
    std::unordered_set<std::string> event_actions;

    for (auto &sub : common_->subscribers) {
        auto it = listeners_.find(sub);
        if (it == listeners_.end()) {
            it = listeners_.emplace(sub, sub->get_listener()).first;
        }
        try {
            auto event = it->second->call(data);
            if (event) {
                event_data.insert(event_data.end(),
                    std::make_move_iterator(event->data.begin()),
                    std::make_move_iterator(event->data.end()));
                event_actions.merge(event->actions);
            }
        } catch (std::exception &e) {
            SPDLOG_ERROR("subscriber failed: {}", e.what());
        }
    }

    if (event_actions.empty() && event_data.empty()) {
        return std::nullopt;
    }

    dds::engine::result res{action_type::record, {}, std::move(event_data)};
    // Currently the only action the extension can perform is block
    if (!event_actions.empty()) {
        // The extension can only handle one action, so we pick the first one
        // available in the list of actions.
        for (const auto &action_str : event_actions) {
            auto it = common_->actions.find(action_str);
            if (it != common_->actions.end()) {
                res.type = it->second.type;
                res.parameters = it->second.parameters;
                break;
            }
        }
    }

    res.force_keep = limiter_.allow();

    return res;
}

void engine::context::get_metrics(metrics::TelemetrySubmitter &msubmitter)
{
    for (const auto &[subscriber, listener] : listeners_) {
        listener->submit_metrics(msubmitter);
    }
}

template <typename T> engine::action parse_action(T &action_object)
{
    auto it = action_object.FindMember("type");
    if (it == action_object.MemberEnd() || !it->value.IsString()) {
        throw parsing_error("no action.type found or unexpected type");
    }
    std::string const type = action_object["type"].GetString();

    engine::action action;
    if (type == "block_request") {
        action.type = engine::action_type::block;
    } else if (type == "redirect_request") {
        action.type = engine::action_type::redirect;
    } else {
        throw parsing_error(
            "unknown action.type " + type + " only block_request supported");
    }

    it = action_object.FindMember("parameters");
    if (it == action_object.MemberEnd() || !it->value.IsObject()) {
        throw parsing_error("no action.parameters found or unexpected type");
    }
    const auto &parameters = action_object["parameters"];
    for (auto iter = parameters.MemberBegin(); iter != parameters.MemberEnd();
         ++iter) {
        if (!iter->name.IsString()) {
            // Unclear if this is even possible
            continue;
        }

        switch (iter->value.GetType()) {
        case rapidjson::kStringType:
            action.parameters[iter->name.GetString()] = iter->value.GetString();
            break;
        case rapidjson::kNumberType: {
            std::string value;
            if (iter->value.IsUint64()) {
                value = std::to_string(iter->value.GetUint64());
            } else if (iter->value.IsInt64()) {
                value = std::to_string(iter->value.GetInt64());
            } else if (iter->value.IsDouble()) {
                value = std::to_string(iter->value.GetDouble());
            }

            action.parameters[iter->name.GetString()] = std::move(value);

            break;
        }
        case rapidjson::kTrueType:
            action.parameters[iter->name.GetString()] = "true";
            break;
        case rapidjson::kFalseType:
            action.parameters[iter->name.GetString()] = "false";
            break;
        default:
            continue;
        }
    }

    return action;
}

template <typename T, typename>
engine::action_map engine::parse_actions(
    const T &doc, const engine::action_map &default_actions)
{
    engine::action_map actions = default_actions;

    auto it = doc.FindMember("actions");
    if (it == doc.MemberEnd()) {
        return actions;
    }

    const auto &actions_array = it->value;
    if (actions_array.GetType() != rapidjson::kArrayType) {
        SPDLOG_ERROR("unexpected 'actions' type {}, expected array",
            static_cast<unsigned>(actions_array.GetType()));
        return actions;
    }

    for (auto &action_object : actions_array.GetArray()) {
        if (action_object.GetType() != rapidjson::kObjectType) {
            SPDLOG_ERROR("unexpected action item type {}, expected object",
                static_cast<unsigned>(action_object.GetType()));
            continue;
        }

        it = action_object.FindMember("id");
        if (it == action_object.MemberEnd() || !it->value.IsString()) {
            SPDLOG_ERROR("no action.id found or unexpected type");
            continue;
        }
        std::string const id = it->value.GetString();
        try {
            actions[id] = parse_action(action_object);
        } catch (const std::exception &e) {
            SPDLOG_ERROR("failed to parse action '{}': {}", id, e.what());
        }
    }

    return actions;
}

engine::ptr engine::from_settings(const dds::engine_settings &eng_settings,
    std::shared_ptr<metrics::TelemetrySubmitter> msubmitter)
{
    auto &&rules_path = eng_settings.rules_file_or_default();
    auto ruleset = engine_ruleset::from_path(rules_path);
    auto actions =
        parse_actions(ruleset.get_document(), engine::default_actions);
    std::shared_ptr engine_ptr{
        engine::create(eng_settings.trace_rate_limit, std::move(actions))};

    try {
        SPDLOG_DEBUG("Will load WAF rules from {}", rules_path);
        // may throw std::exception
        const subscriber::ptr waf =
            waf::instance::from_settings(eng_settings, ruleset, *msubmitter);
        engine_ptr->subscribe(waf);
    } catch (...) {
        DD_STDLOG(DD_STDLOG_WAF_INIT_FAILED, rules_path);
        throw;
    }

    return engine_ptr;
}

// NOLINTNEXTLINE
const engine::action_map engine::default_actions = {
    {"block", {engine::action_type::block,
                  {{"status_code", "403"}, {"type", "auto"}}}},
};

} // namespace dds
