// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "config.hpp"
#include "config_aggregators/config_aggregator.hpp"
#include "engine.hpp"
#include "listener.hpp"
#include "metrics.hpp"
#include "parameter.hpp"
#include "remote_config/protocol/client.hpp"
#include <optional>
#include <rapidjson/document.h>
#include <utility>

namespace dds::remote_config {

//// ENGINE PROXY LISTENER
class engine_listener : public listener_base {
public:
    explicit engine_listener(engine::ptr engine,
        std::shared_ptr<metrics::TelemetrySubmitter> msubmitter,
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

    [[nodiscard]] std::unordered_map<std::string_view, protocol::capabilities_e>
    get_supported_products() override
    {
        return {{asm_product,
                    protocol::capabilities_e::ASM_EXCLUSIONS |
                        protocol::capabilities_e::ASM_CUSTOM_BLOCKING_RESPONSE |
                        protocol::capabilities_e::ASM_REQUEST_BLOCKING |
                        protocol::capabilities_e::ASM_RESPONSE_BLOCKING |
                        protocol::capabilities_e::ASM_CUSTOM_RULES |
                        protocol::capabilities_e::ASM_TRUSTED_IPS},
            {asm_dd_product, protocol::capabilities_e::ASM_DD_RULES},
            {asm_data_product,
                protocol::capabilities_e::ASM_IP_BLOCKING |
                    protocol::capabilities_e::ASM_USER_BLOCKING}};
    }

protected:
    static constexpr std::string_view asm_product = "ASM";
    static constexpr std::string_view asm_dd_product = "ASM_DD";
    static constexpr std::string_view asm_data_product = "ASM_DATA";

    std::unordered_map<std::string_view, config_aggregator_base::unique_ptr>
        aggregators_;
    engine::ptr engine_;
    rapidjson::Document ruleset_;
    std::unordered_set<config_aggregator_base *> to_commit_;
    std::shared_ptr<metrics::TelemetrySubmitter> msubmitter_;
};

} // namespace dds::remote_config
