// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "engine_settings.hpp"
#include "service.hpp"
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
    virtual ~service_manager()
    {
        std::lock_guard guard{mutex_};
        cache_.clear();
    }

    virtual std::shared_ptr<service> get_or_create_service(
        const engine_settings &settings,
        const remote_config::settings &rc_settings,
        const telemetry_settings &telemetry_settings);

    void notify_of_rc_updates(std::string_view shmem_path);

    void dump_table();

protected:
    class cache_key {
    public:
        cache_key(engine_settings engine_settings,
            remote_config::settings rc_settings,
            telemetry_settings telemetry_settings)
            : engine_settings_{std::move(engine_settings)},
              rc_settings_{std::move(rc_settings)},
              telemetry_settings_{std::move(telemetry_settings)},
              hash_{dds::hash(
                  engine_settings_, rc_settings_, telemetry_settings_)}
        {}

        bool operator==(const cache_key &other) const
        {
            return engine_settings_ == other.engine_settings_ &&
                   rc_settings_ == other.rc_settings_ &&
                   telemetry_settings_ == other.telemetry_settings_;
        }

        struct hash {
            std::size_t operator()(const cache_key &key) const
            {
                return key.hash_;
            }
        };

        [[nodiscard]] const std::string &get_shmem_path() const
        {
            return rc_settings_.shmem_path;
        }

    private:
        engine_settings engine_settings_;
        remote_config::settings rc_settings_;
        telemetry_settings telemetry_settings_;
        std::size_t hash_;

        friend struct ::fmt::formatter<dds::service_manager::cache_key>;
    };

    using cache_t =
        std::unordered_map<cache_key, std::weak_ptr<service>, cache_key::hash>;

    void cleanup_cache(); // mutex_ must be held when calling this

    std::mutex mutex_;
    cache_t cache_;
    std::shared_ptr<service> last_service_; // keep always one

    friend struct ::fmt::formatter<dds::service_manager::cache_key>;
};

} // namespace dds

template <> struct fmt::formatter<dds::service_manager::cache_key> {
    constexpr auto parse(fmt::format_parse_context &ctx) { return ctx.begin(); }

    template <typename FormatContext>
    auto format(
        const dds::service_manager::cache_key &key, FormatContext &ctx) const
    {
        return fmt::format_to(ctx.out(),
            "{{rc_settings={}, telemetry_settings={}, engine_settings={}}}",
            key.rc_settings_, key.telemetry_settings_, key.engine_settings_);
    }
};
