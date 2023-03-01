// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <atomic>
#include <string>
#include <thread>
#include <vector>

#include "../service_identifier.hpp"
#include "http_api.hpp"
#include "product.hpp"
#include "protocol/client.hpp"
#include "protocol/tuf/get_configs_request.hpp"
#include "protocol/tuf/get_configs_response.hpp"
#include "settings.hpp"
#include "utils.hpp"

namespace dds::remote_config {

struct config_path {
    static config_path from_path(const std::string &path);

    std::string id;
    std::string product;
};

class client {
public:
    using ptr = std::unique_ptr<client>;
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    client(std::unique_ptr<http_api> &&arg_api, service_identifier sid,
        remote_config::settings settings,
        const std::vector<product> &products = {},
        std::vector<protocol::capabilities_e> capabilities = {});
    virtual ~client() = default;

    client(const client &) = delete;
    client(client &&) = default;
    client &operator=(const client &) = delete;
    client &operator=(client &&) = delete;

    static client::ptr from_settings(const service_identifier &sid,
        const remote_config::settings &settings,
        std::vector<remote_config::product> &&products,
        std::vector<protocol::capabilities_e> capabilities);

    virtual bool poll();

    [[nodiscard]] const service_identifier &get_service_identifier()
    {
        return sid_;
    }

protected:
    [[nodiscard]] protocol::get_configs_request generate_request() const;
    bool process_response(const protocol::get_configs_response &response);

    std::unique_ptr<http_api> api_;

    std::string id_;
    const service_identifier sid_;
    const remote_config::settings settings_;

    // remote config state
    std::string last_poll_error_;
    std::string opaque_backend_state_;
    int targets_version_{0};

    // supported products
    std::unordered_map<std::string, product> products_;

    std::vector<protocol::capabilities_e> capabilities_;
};

} // namespace dds::remote_config
