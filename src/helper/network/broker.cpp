// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "broker.hpp"
#include "../exception.hpp"
#include "proto.hpp"
#include <chrono>
#include <msgpack.hpp>
#include <spdlog/spdlog.h>
#include <sstream>

namespace {

bool default_reference_func( // NOLINTNEXTLINE
    msgpack::type::object_type /*type*/, std::size_t /*len*/, void *)
{
    return true;
}
} // namespace

namespace dds::network {

request broker::recv(std::chrono::milliseconds initial_timeout) const
{
    socket_->set_recv_timeout(initial_timeout);

    header_t h;
    std::size_t res = // NOLINTNEXTLINE
        socket_->recv(reinterpret_cast<char *>(&h), sizeof(header_t));
    if (res == 0UL) {
        throw client_disconnect{};
    }
    if (res != sizeof(header_t)) {
        // The sender probably closed the socket
        throw std::length_error(
            "Not enough data for header:" + std::to_string(res) + " bytes");
    }

    // TODO: remove or increase this dramatically with WAF 1.5.0
    static msgpack::unpack_limit limits(max_array_size, max_map_size,
        max_string_length, max_binary_size, max_extension_size, max_depth);

    msgpack::unpacker u(&default_reference_func, MSGPACK_NULLPTR,
        MSGPACK_UNPACKER_INIT_BUFFER_SIZE, limits); // NOLINT

    if (h.size >= max_msg_body_size) {
        throw std::length_error(
            "Message body too large: " + std::to_string(h.size));
    }
    // Allocate a buffer of the message size
    u.reserve_buffer(h.size);

    static constexpr auto timeout_msg_body{std::chrono::milliseconds{300}};
    socket_->set_recv_timeout(timeout_msg_body);
    res = socket_->recv(u.buffer(), h.size);
    if (res != h.size) {
        throw std::length_error(
            "Not enough data for message body:" + std::to_string(res) +
            " bytes, required " + std::to_string(h.size) + " bytes");
    }
    u.buffer_consumed(h.size);

    msgpack::object_handle oh;
    if (!u.next(oh)) {
        throw bad_cast("Invalid msgpack message");
    }

    return oh.get().as<network::request>();
}

bool broker::send(const base_response &msg) const
{
    std::stringstream ss;
    msgpack::pack(ss, msg);
    const std::string &buffer = ss.str();

    // TODO: Add check to ensure buffer.size() fits in uint32_t
    header_t h = {"dds", (uint32_t)buffer.size()};

    // NOLINTNEXTLINE
    auto res = socket_->send(reinterpret_cast<char *>(&h), sizeof(header_t));
    if (res != sizeof(header_t)) {
        return false;
    }

    res = socket_->send(buffer.c_str(), buffer.size());
    return res == buffer.size();
}

} // namespace dds::network
