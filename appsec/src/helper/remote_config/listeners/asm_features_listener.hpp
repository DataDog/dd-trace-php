// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "config.hpp"
#include "listener.hpp"
#include "service_config.hpp"

namespace dds::remote_config {

class asm_features_listener : public listener_base {
public:
    explicit asm_features_listener(
        std::shared_ptr<dds::service_config> service_config)
        : service_config_(std::move(service_config)){};
    void on_update(const config &config) override;
    void on_unapply(const config & /*config*/) override
    {
        service_config_->unset_asm();
    }

    [[nodiscard]] std::unordered_map<std::string_view, protocol::capabilities_e>
    get_supported_products() override
    {
        return {{"ASM_FEATURES", protocol::capabilities_e::ASM_ACTIVATION}};
    }

    void init() override {}
    void commit() override {}

protected:
    std::shared_ptr<service_config> service_config_;
};

} // namespace dds::remote_config
