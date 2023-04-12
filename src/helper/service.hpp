// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "engine.hpp"
#include "exception.hpp"
#include "remote_config/client_handler.hpp"
#include "service_config.hpp"
#include "service_identifier.hpp"
#include "std_logging.hpp"
#include "utils.hpp"
#include <memory>
#include <spdlog/spdlog.h>
#include <unordered_map>

namespace dds {

using namespace std::chrono_literals;

class service {
public:
    using ptr = std::shared_ptr<service>;

    service(std::shared_ptr<engine> engine,
        std::shared_ptr<service_config> service_config,
        dds::remote_config::client_handler::ptr &&client_handler);

    service(const service &) = delete;
    service &operator=(const service &) = delete;

    service(service &&) = delete;
    service &operator=(service &&) = delete;

    static service::ptr from_settings(service_identifier &&id,
        const dds::engine_settings &eng_settings,
        const remote_config::settings &rc_settings,
        std::map<std::string_view, std::string> &meta,
        std::map<std::string_view, double> &metrics, bool dynamic_enablement);

    [[nodiscard]] std::shared_ptr<engine> get_engine() const
    {
        // TODO make access atomic?
        return engine_;
    }

    [[nodiscard]] std::shared_ptr<service_config> get_service_config() const
    {
        // TODO make access atomic?
        return service_config_;
    }

protected:
    std::shared_ptr<engine> engine_;
    std::shared_ptr<service_config> service_config_;
    dds::remote_config::client_handler::ptr client_handler_;
};

} // namespace dds
