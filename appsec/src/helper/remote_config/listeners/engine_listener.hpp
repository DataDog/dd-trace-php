// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../../engine.hpp"
#include "../product.hpp"
#include "config_aggregators/config_aggregator.hpp"
#include "listener.hpp"
#include <rapidjson/document.h>

namespace dds::remote_config {

//// ENGINE PROXY LISTENER
class engine_listener : public listener_base {
public:
    explicit engine_listener(std::shared_ptr<engine> engine,
        std::shared_ptr<telemetry::telemetry_submitter> msubmitter,
        const std::string &rules_file = {});
    engine_listener(const engine_listener &) = delete;
    engine_listener(engine_listener &&) = default;
    engine_listener &operator=(const engine_listener &) = delete;
    engine_listener &operator=(engine_listener &&) = delete;

    ~engine_listener() override = default;

    void init() override;
    void on_update(const config &config) override;
    void on_unapply(const config &config) override;
    void commit() override;

    [[nodiscard]] std::unordered_set<product> get_supported_products() override
    {
        return {known_products::ASM, known_products::ASM_DD,
            known_products::ASM_DATA};
    }

protected:
    std::unordered_map<product, config_aggregator_base::unique_ptr>
        aggregators_;
    std::shared_ptr<engine> engine_;
    rapidjson::Document ruleset_;
    std::unordered_set<config_aggregator_base *> to_commit_;
    std::shared_ptr<telemetry::telemetry_submitter> msubmitter_;
};

} // namespace dds::remote_config
