// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "engine.hpp"
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
    virtual ~service_manager() = default;
    service_manager() = default;

    virtual std::shared_ptr<service> create_service(service_identifier &&id,
        const engine_settings &settings,
        const remote_config::settings &rc_settings, bool dynamic_enablement);

protected:
    using cache_t = std::unordered_map<service_identifier,
        std::weak_ptr<service>, service_identifier::hash>;

    void cleanup_cache(); // mutex_ must be held when calling this

    // TODO this should be some sort of time-based LRU cache
    service::ptr last_service_;
    std::mutex mutex_;
    cache_t cache_;
};

} // namespace dds
