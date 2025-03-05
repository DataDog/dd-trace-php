// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "engine.hpp"
#include "engine_settings.hpp"
#include "exception.hpp"
#include "network/proto.hpp"
#include "service.hpp"
#include "std_logging.hpp"
#include "subscriber/waf.hpp"
#include "utils.hpp"
#include <memory>
#include <mutex>
#include <spdlog/spdlog.h>
#include <unordered_map>

namespace dds {

class service_manager {
public:
    service_manager() = default;
    service_manager(const service_manager &) = delete;
    service_manager &operator=(const service_manager &) = delete;
    service_manager(service_manager &&) = delete;
    service_manager &operator=(service_manager &&) = delete;
    virtual ~service_manager() = default;

    virtual std::shared_ptr<service> create_service(
        const engine_settings &settings,
        const remote_config::settings &rc_settings);

    void notify_of_rc_updates(std::string_view shmem_path);

protected:
    class cache_key {
    public:
        cache_key(engine_settings engine_settings,
            remote_config::settings config_settings)
            : engine_settings_{std::move(engine_settings)},
              config_settings_{std::move(config_settings)},
              hash_{dds::hash(engine_settings_, config_settings_)}
        {}

        bool operator==(const cache_key &other) const
        {
            return engine_settings_ == other.engine_settings_ &&
                   config_settings_ == other.config_settings_;
        }

        struct hash {
            std::size_t operator()(const cache_key &key) const
            {
                return key.hash_;
            }
        };

        [[nodiscard]] const std::string &get_shmem_path() const
        {
            return config_settings_.shmem_path;
        }

    private:
        engine_settings engine_settings_;
        remote_config::settings config_settings_;
        std::size_t hash_;
    };

    using cache_t =
        std::unordered_map<cache_key, std::weak_ptr<service>, cache_key::hash>;

    void cleanup_cache(); // mutex_ must be held when calling this

    std::mutex mutex_;
    cache_t cache_;
    std::shared_ptr<service> last_service_; // keep always one
};

} // namespace dds
