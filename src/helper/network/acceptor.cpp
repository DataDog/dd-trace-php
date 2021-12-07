// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "acceptor.hpp"
#include "../exception.hpp"
#include <cerrno>
#include <chrono>
#include <iostream>
#include <spdlog/spdlog.h>
#include <stdexcept>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <sys/un.h>
#include <system_error>
#include <unistd.h>

using namespace std::chrono_literals;

namespace dds::network::local {

acceptor::acceptor(const std::string_view &sv)
    : sock_(::socket(AF_UNIX, SOCK_STREAM, 0))
{
    if (sock_ == -1) {
        throw std::system_error(errno, std::generic_category());
    }

    struct sockaddr_un addr {
    };
    addr.sun_family = AF_UNIX;
    if (sv.size() > sizeof(addr.sun_path) - 1) {
        throw std::invalid_argument{"socket path too long"};
    }
    strcpy(static_cast<char *>(addr.sun_path), sv.data()); // NOLINT

    // Remove the existing socket
    ::unlink(static_cast<char *>(addr.sun_path));

    socklen_t len = sv.size() + sizeof(addr.sun_family);
    // NOLINTNEXTLINE
    auto res = ::bind(sock_, reinterpret_cast<struct sockaddr *>(&addr), len);
    if (res == -1) {
        throw std::system_error(errno, std::generic_category());
    }

    ::chmod(sv.data(), 0777); // NOLINT
    static constexpr int backlog = 50;
    if (::listen(sock_, backlog) == -1) {
        throw std::system_error(errno, std::generic_category());
    }
}

void acceptor::set_accept_timeout(std::chrono::seconds timeout)
{
    struct timeval tv = {timeout.count(), 0};
    int res = setsockopt(sock_, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof(tv));
    if (res == -1) {
        throw std::system_error(errno, std::generic_category());
    }
}

socket::ptr acceptor::accept()
{
    struct sockaddr_un addr {
    };
    socklen_t len = sizeof(addr);

    // NOLINTNEXTLINE(android-cloexec-accept,cppcoreguidelines-pro-type-reinterpret-cast)
    int s = ::accept(sock_, reinterpret_cast<struct sockaddr *>(&addr), &len);
    if (s == -1) {
        if (errno == EAGAIN) {
            throw dds::timeout_error();
        }

        throw std::system_error(errno, std::generic_category());
    }

    SPDLOG_DEBUG("New socket: {}", s);
    return std::make_unique<socket>(s);
}

} // namespace dds::network::local
