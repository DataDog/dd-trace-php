// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <set>
#include <spdlog/fmt/ostr.h>
#include <spdlog/spdlog.h>

#include "engine.hpp"
#include "exception.hpp"
#include "parameter_view.hpp"
#include "std_logging.hpp"

namespace dds {

void engine::subscribe(const subscriber::ptr &sub)
{
    for (const auto &addr : sub->get_subscriptions()) {
        auto it = subscriptions_.find(addr);
        if (it == subscriptions_.end()) {
            subscriptions_.emplace(
                addr, std::move(std::vector<subscriber::ptr>{sub}));
        } else {
            it->second.push_back(sub);
        }
    }
}

result engine::context::publish(parameter &&param)
{
    // Once the parameter reaches this function, it is guaranteed to be
    // owned by the engine.
    prev_published_params_.push_back(std::move(param));

    parameter_view data(prev_published_params_.back());
    if (!data.is_map()) {
        throw invalid_object(".", "not a map");
    }

    std::set<subscriber::ptr> sub_set;
    for (const auto &entry : data) {
        auto key = entry.key();
        DD_STDLOG(DD_STDLOG_IG_DATA_PUSHED, key);
        auto it = subscriptions_.find(key);
        if (it == subscriptions_.end()) {
            continue;
        }
        for (auto &&sub : it->second) { sub_set.insert(sub); }
    }

    // Now that we have found the required subscriptions, find the current
    // context and pass the data.
    //
    // TODO: The engine will have to collate the results from all of the
    //       subscribers which return a record or block action, however
    //       there is only one subscriber for now and eventually the
    //       subscribers will not return JSON.
    result res;
    for (const auto &sub : sub_set) {
        auto it = listeners_.find(sub);
        if (it == listeners_.end()) {
            it = listeners_.emplace(sub, sub->get_listener()).first;
        }
        try {
            auto call_res = it->second->call(data);
            if (call_res.value > res.value) {
                res = std::move(call_res);
            }
        } catch (std::exception &e) {
            SPDLOG_ERROR("subscriber failed: {}", e.what());
        }
    }

    if (res.value != result::code::ok && !limiter_.allow()) {
        return result{result::code::ok};
    }

    return res;
}

void engine::context::get_meta_and_metrics(
    std::map<std::string_view, std::string> &meta,
    std::map<std::string_view, double> &metrics)
{
    for (const auto &[subscriber, listener] : listeners_) {
        listener->get_meta_and_metrics(meta, metrics);
    }
}

} // namespace dds
