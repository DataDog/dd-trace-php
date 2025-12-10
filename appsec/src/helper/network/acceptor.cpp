// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "acceptor.hpp"
#include "socket.hpp"
#include <cerrno>
#include <chrono>
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
{
    // NOLINTNEXTLINE(android-cloexec-socket,cppcoreguidelines-prefer-member-initializer)
    sock_ = ::socket(AF_UNIX, SOCK_STREAM, 0);
    if (sock_ == -1) {
        throw std::system_error(errno, std::generic_category());
    }

    try {
        struct sockaddr_un addr {};
        addr.sun_family = AF_UNIX;
        if (sv.size() > sizeof(addr.sun_path) - 1) {
            throw std::invalid_argument{"socket path too long"};
        }
        strcpy(static_cast<char *>(addr.sun_path), sv.data()); // NOLINT

        // Remove the existing socket
        int res = ::unlink(static_cast<char *>(addr.sun_path));
        if (res == -1 && errno != ENOENT) {
            SPDLOG_ERROR("Failed to unlink {}: errno {}", addr.sun_path, errno);
            throw std::system_error(errno, std::generic_category());
        }
        SPDLOG_DEBUG("Unlinked {}", addr.sun_path);

        res =
            // NOLINTNEXTLINE
            ::bind(sock_, reinterpret_cast<struct sockaddr *>(&addr),
                sizeof(addr));
        if (res == -1) {
            SPDLOG_ERROR(
                "Failed to bind socket to {}: errno {}", addr.sun_path, errno);
            throw std::system_error(errno, std::generic_category());
        }

        res = ::chmod(sv.data(), 0777); // NOLINT
        if (res == -1) {
            SPDLOG_ERROR(
                "Failed to chmod socket {}: errno {}", addr.sun_path, errno);
            throw std::system_error(errno, std::generic_category());
        }

        static constexpr int backlog = 50;
        if (::listen(sock_, backlog) == -1) {
            throw std::system_error(errno, std::generic_category());
        }
        SPDLOG_INFO("Started listening on {}", sv);
    } catch (const std::exception &e) {
        ::close(sock_);
        throw;
    }
}

void acceptor::set_accept_timeout(std::chrono::seconds timeout)
{
    struct timeval tv = {timeout.count(), 0};
    int const res = setsockopt(sock_, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof(tv));
    if (res == -1) {
        throw std::system_error(errno, std::generic_category());
    }
}

std::unique_ptr<base_socket> acceptor::accept()
{
    struct sockaddr_un addr {};
    socklen_t len = sizeof(addr);

    // NOLINTNEXTLINE
    int s = ::accept(sock_, reinterpret_cast<struct sockaddr *>(&addr), &len);
    if (s == -1) {
        if (errno == EINTR || errno == EAGAIN) {
            return {};
        }

        throw std::system_error(errno, std::generic_category());
    }

    SPDLOG_DEBUG("accept() returned a new socket: {}", s);
    return std::make_unique<socket>(s);
}

} // namespace dds::network::local
