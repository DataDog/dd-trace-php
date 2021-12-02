// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#ifndef CLIENT_HPP
#define CLIENT_HPP

#include <cstdint>

#include "config.hpp"
#include "engine.hpp"
#include "engine_pool.hpp"
#include "network/broker.hpp"
#include "network/proto.hpp"
#include "network/socket.hpp"
#include <optional>

namespace dds {

class client {
  public:
    client(std::shared_ptr<engine_pool> engine_pool,
        network::base_broker::ptr &&broker)
        : engine_pool_(std::move(engine_pool)), broker_(std::move(broker))
    {}

    client(std::shared_ptr<engine_pool> engine_pool,
        network::base_socket::ptr &&socket)
        : engine_pool_(std::move(engine_pool)), 
          broker_(std::make_unique<network::broker>(std::move(socket)))
    {}

    ~client() = default;
    client(const client &) = delete;
    client &operator=(const client &) = delete;
    client(client &&) = delete;
    client &operator=(client &&) = delete;

    bool handle_command(const network::client_init::request&);
    // NOLINTNEXTLINE(google-runtime-references)
    bool handle_command(network::request_init::request&);
    // NOLINTNEXTLINE(google-runtime-references)
    bool handle_command(network::request_shutdown::request&);

    bool run_client_init();
    bool run_request();
    bool run_once();

  protected:
    bool initialised{false};
    uint32_t version{};
    network::base_broker::ptr broker_;
    std::shared_ptr<engine_pool> engine_pool_;
    std::shared_ptr<engine> engine_;
    std::optional<engine::context> context_;
};

} // namespace dds

#endif // CLIENT_HPP
