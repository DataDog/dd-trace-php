// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "config.hpp"
#include "listener.hpp"
#include "service_config.hpp"
#include <rapidjson/document.h>

namespace dds::remote_config {

class asm_features_listener : public listener_base {
public:
    explicit asm_features_listener(
        std::shared_ptr<dds::service_config> service_config,
        bool dynamic_enablement, bool api_security_enabled)
        : service_config_(std::move(service_config)),
          dynamic_enablement_(dynamic_enablement),
          api_security_enabled_(api_security_enabled){};
    void on_update(const config &config) override;
    void on_unapply(const config & /*config*/) override
    {
        service_config_->unset_asm();
    }

    [[nodiscard]] std::unordered_map<std::string_view, protocol::capabilities_e>
    get_supported_products() override
    {
        protocol::capabilities_e capabilities = protocol::capabilities_e::NONE;

        if (dynamic_enablement_) {
            capabilities = protocol::capabilities_e::ASM_ACTIVATION;
        }
        if (api_security_enabled_) {
            capabilities |=
                protocol::capabilities_e::ASM_API_SECURITY_SAMPLE_RATE;
        }

        if (capabilities != protocol::capabilities_e::NONE) {
            return {{asm_features, capabilities}};
        }
        return {};
    }

    void init() override {}
    void commit() override {}

protected:
    static constexpr std::string_view asm_features = "ASM_FEATURES";
    void parse_asm(const rapidjson::Document &serialized_doc);
    double parse_api_security(const rapidjson::Document &serialized_doc);
    std::shared_ptr<service_config> service_config_;
    bool dynamic_enablement_;
    bool api_security_enabled_;
};

} // namespace dds::remote_config
