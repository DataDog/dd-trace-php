// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <mutex>
#include <set>
#include <string>
#include <vector>

#include "../telemetry.hpp"
#include "listeners/listener.hpp"
#include "product.hpp"
#include "settings.hpp"

extern "C" {
struct ddog_RemoteConfigReader;
}

namespace dds::remote_config {

struct config_path {
    static config_path from_path(const std::string &path);

    std::string id;
    std::string product;
};

class client {
    client(remote_config::settings settings,
        std::vector<std::shared_ptr<listener_base>> listeners,
        std::shared_ptr<telemetry::telemetry_submitter> msubmitter);

public:
    ~client() = default;

    client(const client &) = delete;
    client(client &&) = delete;
    client &operator=(const client &) = delete;
    client &operator=(client &&) = delete;

    static std::unique_ptr<client> from_settings(
        const remote_config::settings &settings,
        std::vector<std::shared_ptr<listener_base>> listeners,
        std::shared_ptr<telemetry::telemetry_submitter> msubmitter);

    bool poll();

protected:
    bool process_response(std::set<config> new_configs);

    std::unique_ptr<ddog_RemoteConfigReader,
        void (*)(ddog_RemoteConfigReader *)>
        reader_;
    remote_config::settings settings_; // just for logging

    std::vector<std::shared_ptr<listener_base>> listeners_;
    std::unordered_map<product, std::vector<listener_base *>>
        listeners_per_product_;                // non-owning index of listeners_
    std::unordered_set<product> all_products_; // keys of listeners_per_product_

    std::set<config> last_configs_;
    std::mutex mutex_;

    std::shared_ptr<telemetry::telemetry_submitter> msubmitter_;
};

} // namespace dds::remote_config
