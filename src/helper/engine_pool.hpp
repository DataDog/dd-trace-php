// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "engine.hpp"
#include "exception.hpp"
#include <memory>
#include <mutex>
#include <unordered_map>

namespace dds {

class engine_pool {
public:
    // NOLINTNEXTLINE(google-runtime-references)
    std::shared_ptr<engine> create_engine(const client_settings &settings,
        std::map<std::string_view, std::string> &meta,
        std::map<std::string_view, double> &metrics);

protected:
    using cache_t = std::unordered_map<client_settings, std::weak_ptr<engine>,
        client_settings::settings_hash>;

    void cleanup_cache(); // mutex_ must be held when calling this

    std::shared_ptr<engine> last_engine_; // always keep the last one
    std::mutex mutex_;
    cache_t cache_;
};

} // namespace dds
