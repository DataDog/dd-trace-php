// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "engine_listener.hpp"
#include "../exception.hpp"
#include "../product.hpp"
#include "config_aggregators/asm_aggregator.hpp"
#include <rapidjson/document.h>
#include <rapidjson/rapidjson.h>
#include <spdlog/spdlog.h>
#include <utility>

namespace dds::remote_config {

engine_listener::engine_listener(std::shared_ptr<engine> engine,
    std::shared_ptr<dds::metrics::telemetry_submitter> msubmitter,
    const std::string & /*rules_file*/)
    : engine_{std::move(engine)}, msubmitter_{std::move(msubmitter)}
{
    aggregators_.emplace(
        known_products::ASM, std::make_unique<asm_aggregator>());
    aggregators_.emplace(
        known_products::ASM_DD, std::make_unique<asm_aggregator>());
    aggregators_.emplace(
        known_products::ASM_DATA, std::make_unique<asm_aggregator>());
}

void engine_listener::init()
{
    ruleset_ = rapidjson::Document(rapidjson::kObjectType);
    to_commit_.clear();
}

void engine_listener::on_update(const config &config)
{
    auto product = config.config_key().product();
    auto it = aggregators_.find(product);
    if (it == aggregators_.end()) {
        throw error_applying_config(
            "unknown product: " + std::string{product.name()});
    }

    auto &aggregator = it->second;
    if (to_commit_.find(aggregator.get()) == to_commit_.end()) {
        aggregator->init(&ruleset_.GetAllocator());
        to_commit_.emplace(aggregator.get());
    }

    aggregator->add(config);
}

void engine_listener::on_unapply(const config &config)
{
    auto product = config.config_key().product();
    auto it = aggregators_.find(product);
    if (it == aggregators_.end()) {
        throw error_applying_config(
            "unknown product: " + std::string{product.name()});
    }

    auto &aggregator = it->second;
    if (to_commit_.find(aggregator.get()) == to_commit_.end()) {
        aggregator->init(&ruleset_.GetAllocator());
        to_commit_.emplace(aggregator.get());
    }

    aggregator->remove(config);
}

void engine_listener::commit()
{
    if (to_commit_.empty()) {
        return;
    }

    for (auto &[product, aggregator] : aggregators_) {
        if (to_commit_.find(aggregator.get()) != to_commit_.end()) {
            aggregator->aggregate(ruleset_);
        }
    }

    engine_->update(ruleset_, *msubmitter_);
}

} // namespace dds::remote_config
