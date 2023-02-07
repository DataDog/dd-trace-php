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
    explicit asm_data_listener(std::shared_ptr<dds::engine> engine)
        : engine_(std::move(engine)){};
    void on_update(const config &config) override;
    void on_unapply(const config & /*config*/) override{};

protected:
    std::shared_ptr<dds::engine> engine_;
};

} // namespace dds::remote_config
