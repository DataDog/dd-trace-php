// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include "socket.hpp"
#include <string_view>

namespace dds::network {

class base_acceptor {
  public:
    using ptr = std::unique_ptr<base_acceptor>;

    base_acceptor() = default;
    base_acceptor(const base_acceptor&) = delete;
    base_acceptor& operator=(const base_acceptor&) = delete;
    base_acceptor(base_acceptor&&) = default;
    base_acceptor& operator=(base_acceptor&&) = default;
    virtual ~base_acceptor() = default;

    [[nodiscard]] virtual base_socket::ptr accept() = 0;
};

namespace local {

class acceptor : public base_acceptor {
  public:
    explicit acceptor(int fd) : sock_{fd} {};
    explicit acceptor(const std::string_view &sv);
    acceptor(const acceptor&) = delete;
    acceptor& operator=(const acceptor&) = delete;
    acceptor(acceptor&&) = default;
    acceptor& operator=(acceptor&&) = default;
    ~acceptor() override = default;

    [[nodiscard]] base_socket::ptr accept() override;

  private:
    int sock_{-1};
};

} // namespace local

} // namespace dds::network
