// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "engine_pool.hpp"
#include "std_logging.hpp"
#include "subscriber/waf.hpp"

#include <mutex>
#include <spdlog/spdlog.h>

namespace dds {

/**
 * @brief create or retrieve from engine engine matching given settings
 * @throws std::exception if creating the waf subscriber fails
 */
std::shared_ptr<engine> engine_pool::create_engine(
    const client_settings &settings)
{
    std::lock_guard guard{mutex_};

    auto hit = cache_.find(settings);
    if (hit != cache_.end()) {
        std::shared_ptr engine_ptr = hit->second.lock();
        if (engine_ptr) { // not expired
            SPDLOG_DEBUG("Cache hit settings {}", settings);
            return engine_ptr;
        }
    }

    // no cache hit
    std::shared_ptr engine_ptr{engine::create(settings.trace_rate_limit)};
    auto &&rules_path = settings.rules_file_or_default();
    try {
        SPDLOG_DEBUG("Will load WAF rules from {}", rules_path);
        // may throw std::exception
        subscriber::ptr waf = waf::instance::from_settings(settings);
        engine_ptr->subscribe(waf);
    } catch (...) {
        DD_STDLOG(DD_STDLOG_WAF_INIT_FAILED, rules_path);
        throw;
    }

    cache_.emplace(settings, engine_ptr);
    last_engine_ = engine_ptr;

    cleanup_cache();

    return engine_ptr;
}

void engine_pool::cleanup_cache()
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
