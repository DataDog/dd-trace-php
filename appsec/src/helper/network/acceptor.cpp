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
    sock_ = owned_fd{::socket(AF_UNIX, SOCK_STREAM, 0)};
    if (sock_.is_empty()) {
        throw std::system_error(errno, std::generic_category());
    }

    struct sockaddr_un addr {};
    std::size_t addr_size;
    addr.sun_family = AF_UNIX;
    bool is_abstract = (!sv.empty() && sv[0] == '@');

    if (is_abstract) {
#ifdef __linux__
        if (sv.size() > sizeof(addr.sun_path)) {
            throw std::invalid_argument{"socket path too long"};
        }
        // Replace @ with null byte for abstract namespace
        addr.sun_path[0] = '\0';
        std::copy_n(sv.data() + 1, sv.size() - 1, &addr.sun_path[1]);
        addr_size = sv.size() + offsetof(struct sockaddr_un, sun_path);
#else
        throw std::runtime_error{
            "Abstract namespace sockets are only supported on Linux"};
#endif
    } else {
        // Filesystem socket
        if (sv.size() > sizeof(addr.sun_path) - 1) {
            throw std::invalid_argument{"socket path too long"};
        }
        std::copy_n(sv.data(), sv.size(), &addr.sun_path[0]);
        // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-constant-array-index)
        addr.sun_path[sv.size()] = '\0';
        addr_size = sizeof(addr);

        // Remove the existing socket
        int res = ::unlink(static_cast<char *>(addr.sun_path));
        if (res == -1 && errno != ENOENT) {
            SPDLOG_ERROR("Failed to unlink {}: errno {}", addr.sun_path, errno);
            throw std::system_error(errno, std::generic_category());
        }
        SPDLOG_DEBUG("Unlinked {}", addr.sun_path);
    }

    int res =
        // NOLINTNEXTLINE
        ::bind(
            sock_.get(), reinterpret_cast<struct sockaddr *>(&addr), addr_size);
    if (res == -1) {
        if (is_abstract) {
            SPDLOG_ERROR("Failed to bind abstract socket: errno {}", errno);
        } else {
            SPDLOG_ERROR(
                "Failed to bind socket to {}: errno {}", addr.sun_path, errno);
        }
        throw std::system_error(errno, std::generic_category());
    }

    if (!is_abstract) {
        res = ::chmod(sv.data(), 0777); // NOLINT
        if (res == -1) {
            SPDLOG_ERROR(
                "Failed to chmod socket {}: errno {}", addr.sun_path, errno);
            throw std::system_error(errno, std::generic_category());
        }
    }

    static constexpr int backlog = 50;
    if (::listen(sock_.get(), backlog) == -1) {
        throw std::system_error(errno, std::generic_category());
    }

    if (is_abstract) {
        SPDLOG_INFO("Started listening on abstract socket: {}", sv);
    } else {
        SPDLOG_INFO("Started listening on {}", sv);
    }
}

void acceptor::set_accept_timeout(std::chrono::seconds timeout)
{
    struct timeval tv = {timeout.count(), 0};
    int const res =
        setsockopt(sock_.get(), SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof(tv));
    if (res == -1) {
        throw std::system_error(errno, std::generic_category());
    }
}

std::unique_ptr<base_socket> acceptor::accept()
{
    struct sockaddr_un addr {};
    socklen_t len = sizeof(addr);

    int s =
        // NOLINTNEXTLINE
        ::accept(sock_.get(), reinterpret_cast<struct sockaddr *>(&addr), &len);
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
