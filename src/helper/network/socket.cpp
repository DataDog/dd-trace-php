// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
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

namespace dds::network::local {

std::size_t socket::recv(char *buffer, std::size_t len)
{
    size_t received = 0;
    while (received < len) {
        ssize_t res =
            ::recv(sock_, &buffer[received], len - received, MSG_WAITALL);
        if (res == -1) {
            throw std::system_error(errno, std::generic_category());
        }
        if (res == 0) {
            break;
        }
        received += static_cast<size_t>(res);
    }

    return received;
}

std::size_t socket::send(const char *buffer, std::size_t len)
{
    ssize_t res = ::send(sock_, buffer, len, 0);
    if (res == -1) {
        throw std::system_error(errno, std::generic_category());
    }

    return res;
}

namespace {
struct timeval from_chrono(std::chrono::milliseconds duration)
{
    static constexpr auto TEN_E3{1000};
    return {.tv_sec = duration.count() / TEN_E3,
        .tv_usec = static_cast<decltype(timeval::tv_usec)>(
            (duration.count() % TEN_E3) * TEN_E3)};
}
} // namespace

void socket::set_send_timeout(std::chrono::milliseconds timeout)
{
    struct timeval tv = from_chrono(timeout);
    int res = ::setsockopt(sock_, SOL_SOCKET, SO_SNDTIMEO, &tv, sizeof(tv));
    if (res == -1) {
        throw std::system_error(errno, std::generic_category());
    }
}
void socket::set_recv_timeout(std::chrono::milliseconds timeout)
{
    struct timeval tv = from_chrono(timeout);
    int res = setsockopt(sock_, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof(tv));
    if (res == -1) {
        throw std::system_error(errno, std::generic_category());
    }
}

} // namespace dds::network::local
