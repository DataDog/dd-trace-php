// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <chrono>
#include <memory>
#include <spdlog/spdlog.h>
#include <string_view>
#include <unistd.h>

namespace dds::network {

class base_socket {
public:
    using ptr = std::unique_ptr<base_socket>;

    base_socket() = default;
    base_socket(const base_socket &) = delete;
    base_socket &operator=(const base_socket &) = delete;
    base_socket(base_socket &&) = default;
    base_socket &operator=(base_socket &&) = default;

    virtual ~base_socket() = default;

    virtual std::size_t recv(char *buffer, std::size_t len) = 0;
    virtual std::size_t send(const char *buffer, std::size_t len) = 0;

    virtual void set_send_timeout(std::chrono::milliseconds timeout) = 0;
    virtual void set_recv_timeout(std::chrono::milliseconds timeout) = 0;
};

namespace local {
class socket : public base_socket {
public:
    explicit socket(int s) : sock_(s) {}
    socket(const socket &) = delete; // could use dup though
    socket &operator=(const socket &) = delete;

    ~socket() override { close(); }

    socket(socket &&other) noexcept : sock_{-1}
    {
        sock_ = other.sock_; // NOLINT
        other.sock_ = -1;
    }
    socket &operator=(socket &&other) noexcept
    {
        close();
        sock_ = other.sock_;
        other.sock_ = -1;
        return *this;
    }

    explicit operator int() const { return sock_; }

    std::size_t recv(char *buffer, std::size_t len) override;
    std::size_t send(const char *buffer, std::size_t len) override;

    void set_send_timeout(std::chrono::milliseconds timeout) override;
    void set_recv_timeout(std::chrono::milliseconds timeout) override;

private:
    void close() noexcept
    {
        if (sock_ == -1) {
            return;
        }
        ::close(sock_);
        SPDLOG_DEBUG("Closing socket {}", sock_);
        sock_ = -1;
    }

    int sock_;
};

} // namespace local

} // namespace dds::network
