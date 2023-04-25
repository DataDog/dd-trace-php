// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "engine_listener.hpp"
#include "config_aggregators/asm_aggregator.hpp"
#include "config_aggregators/asm_data_aggregator.hpp"
#include "config_aggregators/asm_dd_aggregator.hpp"
#include "exception.hpp"
#include "json_helper.hpp"
#include "remote_config/exception.hpp"
#include "spdlog/spdlog.h"
#include <optional>
#include <rapidjson/document.h>
#include <rapidjson/rapidjson.h>
#include <type_traits>

namespace dds::remote_config {

engine_listener::engine_listener(
    engine::ptr engine, const std::string &rules_file)
    : engine_(std::move(engine))
{
    aggregators_.emplace(asm_product, std::make_unique<asm_aggregator>());
    aggregators_.emplace(
        asm_dd_product, std::make_unique<asm_dd_aggregator>(rules_file));
    aggregators_.emplace(
        asm_data_product, std::make_unique<asm_data_aggregator>());
}

void engine_listener::init()
{
    ruleset_ = rapidjson::Document(rapidjson::kObjectType);
    for (auto &[product, aggregator] : aggregators_) {
        aggregator->init(&ruleset_.GetAllocator());
    }
}

void engine_listener::on_update(const config &config)
{
    auto it = aggregators_.find(config.product);
    if (it != aggregators_.end()) {
        it->second->add(config);
    }
}

void engine_listener::on_unapply(const config &config)
{
    auto it = aggregators_.find(config.product);
    if (it != aggregators_.end()) {
        it->second->remove(config);
    }
}

void engine_listener::commit()
{
    for (auto &[product, aggregator] : aggregators_) {
        aggregator->aggregate(ruleset_);
    }

    // TODO find a way to provide this information to the service
    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    engine_ruleset ruleset = dds::engine_ruleset(std::move(ruleset_));
    engine_->update(ruleset, meta, metrics);
}

} // namespace dds::remote_config
