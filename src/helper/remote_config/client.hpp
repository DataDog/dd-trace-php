// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <string>
#include <vector>

#include "http_api.hpp"
#include "product.hpp"
#include "protocol/client.hpp"
#include "protocol/tuf/get_configs_request.hpp"
#include "protocol/tuf/get_configs_response.hpp"

namespace dds::remote_config {

enum class remote_config_result {
    success,
    error,
};

class invalid_path : public std::exception {};

struct config_path {
    static config_path from_path(const std::string &path);
    std::string id;
    std::string product;
};

class client {
public:
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    client(std::unique_ptr<http_api> &&arg_api, std::string &&id,
        std::string &&runtime_id,
        // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
        std::string &&tracer_version, std::string &&service, std::string &&env,
        std::string &&app_version, const std::vector<product> &products)
        : api_(std::move(arg_api)), id_(id), runtime_id_(runtime_id),
          tracer_version_(tracer_version), service_(service), env_(env),
          app_version_(app_version)
    {
        for (auto const &product : products) {
            products_.insert(std::pair<std::string, remote_config::product>(
                product.get_name(), product));
        }
    };

    remote_config_result poll();

private:
    [[nodiscard]] protocol::get_configs_request generate_request() const;
    remote_config_result process_response(
        const protocol::get_configs_response &response);

    std::unique_ptr<http_api> api_;
    std::string id_;
    std::string runtime_id_;
    std::string tracer_version_;
    std::string service_;
    std::string env_;
    std::string app_version_;
    std::string last_poll_error_;
    std::string opaque_backend_state_;
    int targets_version_{0};
    std::map<std::string, product> products_;
};

} // namespace dds::remote_config
