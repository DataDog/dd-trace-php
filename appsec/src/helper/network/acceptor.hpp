// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../utils.hpp"
#include "socket.hpp"
#include <chrono>
#include <string_view>

namespace dds::network {

class base_acceptor {
public:
    base_acceptor() = default;
    base_acceptor(const base_acceptor &) = delete;
    base_acceptor &operator=(const base_acceptor &) = delete;
    base_acceptor(base_acceptor &&) = default;
    base_acceptor &operator=(base_acceptor &&) = default;
    virtual ~base_acceptor() = default;

    virtual void set_accept_timeout(std::chrono::seconds timeout) = 0;
    [[nodiscard]] virtual std::unique_ptr<base_socket> accept() = 0;
};

namespace local {

class acceptor : public base_acceptor {
public:
    explicit acceptor(owned_fd fd) : sock_{std::move(fd)} {};
    explicit acceptor(const std::string_view &sv);
    acceptor(const acceptor &) = delete;
    acceptor &operator=(const acceptor &) = delete;

    void set_accept_timeout(std::chrono::seconds timeout) override;
    [[nodiscard]] std::unique_ptr<base_socket> accept() override;

private:
    owned_fd sock_;
};

} // namespace local

} // namespace dds::network
