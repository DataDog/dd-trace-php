// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "proto.hpp"
#include "socket.hpp"
#include <chrono>

namespace dds::network {

class base_broker {
public:
    base_broker() = default;
    base_broker(const base_broker &) = delete;
    base_broker &operator=(const base_broker &) = delete;
    base_broker(base_broker &&) = default;
    base_broker &operator=(base_broker &&) = default;
    virtual ~base_broker() = default;

    [[nodiscard]] virtual request recv(
        std::chrono::milliseconds initial_timeout) const = 0;
    [[nodiscard]] virtual bool send(
        const std::vector<std::shared_ptr<base_response>> &messages) const = 0;
    [[nodiscard]] virtual bool send(
        const std::shared_ptr<base_response> &message) const = 0;
};

class broker : public base_broker {
public:
    // msgpack limits
    static constexpr std::size_t max_array_size = 256;
    static constexpr std::size_t max_map_size = 256;
    static constexpr std::size_t max_string_length = 4096;
    static constexpr std::size_t max_binary_size = 0;
    static constexpr std::size_t max_extension_size = 0;
    static constexpr std::size_t max_depth = 32;

    // other limits
    static constexpr std::size_t max_msg_body_size = 65536;

    explicit broker(std::unique_ptr<base_socket> &&socket)
        : socket_(std::move(socket))
    {}
    broker(const broker &) = delete;
    broker &operator=(const broker &) = delete;
    broker(broker &&) = default;
    broker &operator=(broker &&) = default;
    ~broker() override = default;

    [[nodiscard]] request recv(
        std::chrono::milliseconds initial_timeout) const override;
    [[nodiscard]] bool send(
        const std::vector<std::shared_ptr<base_response>> &messages)
        const override;
    [[nodiscard]] bool send(
        const std::shared_ptr<base_response> &message) const override;

protected:
    std::unique_ptr<base_socket> socket_;
};

} // namespace dds::network
