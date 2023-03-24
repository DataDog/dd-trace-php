// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../engine.hpp"
#include "config.hpp"
#include "listener.hpp"
#include "parameter.hpp"
#include <optional>
#include <rapidjson/document.h>
#include <utility>

namespace dds::remote_config {

class asm_data_listener : public product_listener_base {
public:
    struct rule_data {
        struct data_with_expiration {
            std::string value;
            std::optional<uint64_t> expiration;
        };

        std::string id;
        std::string type;
        std::map<std::string, data_with_expiration> data;
    };

    explicit asm_data_listener(std::shared_ptr<dds::engine> engine)
        : engine_(std::move(engine)){};
    void on_update(const config &config) override;
    void on_unapply(const config & /*config*/) override{};
    const protocol::capabilities_e get_capabilities() override
    {
        return protocol::capabilities_e::ASM_IP_BLOCKING |
               protocol::capabilities_e::ASM_USER_BLOCKING;
    }
    const std::string_view get_name() override { return "ASM_DATA"; }

    void init() override { rules_data_.clear(); }
    void commit() override;

protected:
    std::shared_ptr<dds::engine> engine_;
    std::unordered_map<std::string, rule_data> rules_data_;
};

} // namespace dds::remote_config
