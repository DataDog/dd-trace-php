// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "service_manager.hpp"

namespace dds {

std::shared_ptr<service> service_manager::get_or_create_service(
    const engine_settings &eng_settings,
    const remote_config::settings &rc_settings,
    const telemetry_settings &telemetry_settings)
{
    const cache_key key{eng_settings, rc_settings, telemetry_settings};
    SPDLOG_DEBUG(
        "Will try to fetch service with cache hash={}", cache_key::hash{}(key));

    bool ran_cleanup = false;

    const std::lock_guard guard{mutex_};
    auto hit = cache_.find(key);
    if (hit != cache_.end()) {
        std::shared_ptr<service> service_ptr = hit->second.lock();
        if (service_ptr) { // not expired
            SPDLOG_DEBUG("Found an existing service for {}", key);
            return service_ptr;
        }

        // expired. Free up the map entry so that we can recreate it
        cleanup_cache();
        ran_cleanup = true;
    }

    SPDLOG_DEBUG("Creating a service for {}", key);

    auto service_ptr =
        service::from_settings(eng_settings, rc_settings, telemetry_settings);
    auto [_, inserted] = cache_.emplace(key, service_ptr);
    if (!inserted) {
        SPDLOG_CRITICAL("Could not register the new service");
        std::abort();
    }

    last_service_ = service_ptr;

    SPDLOG_TRACE("Service {} created for key {}",
        static_cast<void *>(service_ptr.get()), key);

    if (!ran_cleanup) {
        cleanup_cache();
    }

    return service_ptr;
}

void service_manager::notify_of_rc_updates(std::string_view shmem_path)
{
    std::vector<std::shared_ptr<service>> services_to_notify;
    {
        const std::lock_guard guard{mutex_};
        for (auto &[key, service_ptr] : cache_) {
            if (key.get_shmem_path() == shmem_path) {
                if (std::shared_ptr<service> service = service_ptr.lock()) {
                    services_to_notify.emplace_back(std::move(service));
                } else {
                    SPDLOG_DEBUG("Service with rc path {} had expired",
                        key.get_shmem_path());
                }
            }
        }
    } // release lock

    SPDLOG_DEBUG(
        "Notifying {} services of RC updates", services_to_notify.size());
    for (auto &service : services_to_notify) {
        service->notify_of_rc_updates();
    }
}

void service_manager::dump_table()
{
#if SPDLOG_ACTIVE_LEVEL <= SPDLOG_LEVEL_DEBUG
    // requires lock to be held
    for (auto &[key, service_ptr] : cache_) {
        if (std::shared_ptr<service> const service = service_ptr.lock()) {
            SPDLOG_DEBUG("RC path {} -> service {}", key.get_shmem_path(),
                static_cast<void *>(service.get()));
        } else {
            SPDLOG_DEBUG("RC path {} -> expired service", key.get_shmem_path());
        }
    }
#endif
}

void service_manager::cleanup_cache()
{
    // NOLINTNEXTLINE(clang-analyzer-deadcode.DeadStores)
    auto entries_before = cache_.size();
    for (auto it = cache_.begin(); it != cache_.end();) {
        if (it->second.expired()) {
            cache_key const key = it->first;
            SPDLOG_TRACE(
                "Service had expired; removing entry with key={}", it->first);
            it = cache_.erase(it);
        } else {
            it++;
        }
    }
    // NOLINTNEXTLINE(clang-analyzer-deadcode.DeadStores)
    auto entries_after = cache_.size();
    SPDLOG_DEBUG("Cleaned up service cache. Entries before: {}, after: {}",
        entries_before, entries_after);
}

} // namespace dds
