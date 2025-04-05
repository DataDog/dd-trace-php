// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../../service_config.hpp"
#include "../product.hpp"
#include "config_aggregators/config_aggregator.hpp"
#include "listener.hpp"
#include <rapidjson/document.h>

namespace dds::remote_config {

class asm_features_listener : public listener_base {
public:
    explicit asm_features_listener(
        std::shared_ptr<dds::service_config> service_config)
        : service_config_(std::move(service_config)){};

    void init() override;
    void on_update(const config &config) override;
    void on_unapply(const config &) override {}
    void commit() override;

    [[nodiscard]] std::unordered_set<product> get_supported_products() override
    {
        return {known_products::ASM_FEATURES};
    }

protected:
    void parse_asm_activation_config();
    void parse_auto_user_instrum_config();

    std::shared_ptr<service_config> service_config_;
    rapidjson::Document ruleset_;
    config_aggregator_base::unique_ptr aggregator_;
};

} // namespace dds::remote_config
