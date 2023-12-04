// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <cstdint>

#include "config.hpp"
#include "engine.hpp"
#include "network/broker.hpp"
#include "network/proto.hpp"
#include "network/socket.hpp"
#include "service_manager.hpp"
#include "worker_pool.hpp"
#include <optional>

namespace dds {

class client {
public:
    // Below this limit the encoding+compression might result on a longer string
    static constexpr int max_plain_schema_allowed = 260;
    client(std::shared_ptr<service_manager> service_manager,
        network::base_broker::ptr &&broker)
        : service_manager_(std::move(service_manager)),
          broker_(std::move(broker))
    {}

    client(std::shared_ptr<service_manager> service_manager,
        network::base_socket::ptr &&socket)
        : service_manager_(std::move(service_manager)),
          broker_(std::make_unique<network::broker>(std::move(socket)))
    {}

    ~client() = default;
    client(const client &) = delete;
    client &operator=(const client &) = delete;
    client(client &&) = delete;
    client &operator=(client &&) = delete;

    bool handle_command(const network::client_init::request &);
    // NOLINTNEXTLINE(google-runtime-references)
    bool handle_command(network::request_init::request &);
    // NOLINTNEXTLINE(google-runtime-references)
    bool handle_command(network::request_exec::request &);
    // NOLINTNEXTLINE(google-runtime-references)
    bool handle_command(network::request_shutdown::request &);
    // NOLINTNEXTLINE(google-runtime-references)
    bool handle_command(network::config_sync::request &);

    bool run_client_init();
    bool run_request();

    [[nodiscard]] std::shared_ptr<service> get_service() const
    {
        return service_;
    }

    // NOLINTNEXTLINE(google-runtime-references)
    void run(worker::queue_consumer &q);
    bool compute_client_status();

protected:
    bool initialised{false};
    uint32_t version{};
    network::base_broker::ptr broker_;
    std::shared_ptr<service_manager> service_manager_;
    std::shared_ptr<service> service_ = {nullptr};
    std::optional<engine::context> context_;
    std::optional<bool> client_enabled_conf;
    bool request_enabled_ = {false};
    std::string runtime_id_;
};

} // namespace dds
