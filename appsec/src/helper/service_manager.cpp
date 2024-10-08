// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "service_manager.hpp"

namespace dds {

std::shared_ptr<service> service_manager::create_service(
    const engine_settings &settings, const remote_config::settings &rc_settings,
    std::map<std::string, std::string> &meta,
    std::map<std::string_view, double> &metrics, bool dynamic_enablement)
{
    const cache_key key{settings, rc_settings};

    const std::lock_guard guard{mutex_};
    auto hit = cache_.find(key);
    if (hit != cache_.end()) {
        auto service_ptr = hit->second.lock();
        if (service_ptr) { // not expired
            SPDLOG_DEBUG(
                "Found an existing service for settings={} rc_settings={}",
                settings, rc_settings);
            return service_ptr;
        }
    }

    SPDLOG_DEBUG("Creating a service for settings={} rc_settings={}", settings,
        rc_settings);

    auto service_ptr = service::from_settings(
        settings, rc_settings, meta, metrics, dynamic_enablement);
    cache_.emplace(key, std::move(service_ptr));

    last_service_ = service_ptr;

    cleanup_cache();

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

void service_manager::cleanup_cache()
{
    for (auto it = cache_.begin(); it != cache_.end();) {
        if (it->second.expired()) {
            it = cache_.erase(it);
        } else {
            it++;
        }
    }
}

} // namespace dds
