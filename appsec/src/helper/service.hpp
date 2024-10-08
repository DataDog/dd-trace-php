// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "engine.hpp"
#include "exception.hpp"
#include "remote_config/client_handler.hpp"
#include "sampler.hpp"
#include "service_config.hpp"
#include "std_logging.hpp"
#include "utils.hpp"
#include <memory>
#include <spdlog/spdlog.h>
#include <unordered_map>

namespace dds {

using namespace std::chrono_literals;

class service {
public:
    service(std::shared_ptr<engine> engine,
        std::shared_ptr<service_config> service_config,
        std::unique_ptr<dds::remote_config::client_handler> &&client_handler,
        std::string rc_path,
        const schema_extraction_settings &schema_extraction_settings = {});

    service(const service &) = delete;
    service &operator=(const service &) = delete;

    service(service &&) = delete;
    service &operator=(service &&) = delete;

    virtual ~service() = default;

    static std::shared_ptr<service> from_settings(
        const dds::engine_settings &eng_settings,
        const remote_config::settings &rc_settings,
        std::map<std::string, std::string> &meta,
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

    [[nodiscard]] std::shared_ptr<sampler> get_schema_sampler()
    {
        return schema_sampler_;
    }

    [[nodiscard]] bool is_remote_config_shmem_path(std::string_view path)
    {
        return rc_path_ == path;
    }

    void notify_of_rc_updates() { client_handler_->poll(); }

protected:
    std::shared_ptr<engine> engine_{};
    std::shared_ptr<service_config> service_config_{};
    std::unique_ptr<dds::remote_config::client_handler> client_handler_{};
    std::shared_ptr<sampler> schema_sampler_;
    std::string rc_path_;
};

} // namespace dds
