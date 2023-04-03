// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include <atomic>
#include <condition_variable>
#include <iostream>
#include <mutex>
#include <network/acceptor.hpp>
#include <network/msgpack_helpers.hpp>
#include <network/socket.hpp>
#include <network/proto.hpp>
#include "helpers.hpp"

namespace dds::fuzzer {

class raw_socket : public network::base_socket {
public:
    raw_socket(const uint8_t *bytes, size_t size) {
        start = new uint8_t[size];
        memcpy(start, bytes, size);
        r = reader(start, size);
    }

    ~raw_socket() { delete[] start; }

    std::size_t recv(char *buffer, std::size_t size) override
    {
        return r.read_bytes(reinterpret_cast<uint8_t*>(buffer), size);
    }

    std::size_t send(const char *buffer, std::size_t len) override
    {
        return len;
    }

    std::size_t discard(std::size_t len) override
    {
        return r.read_bytes(nullptr, len);
    }

    void set_send_timeout(std::chrono::milliseconds timeout) override {}
    void set_recv_timeout(std::chrono::milliseconds timeout) override {}
protected:
    uint8_t *start{nullptr};
    reader r;
};

class acceptor : public network::base_acceptor {
public:
    ~acceptor() override = default;

    void push_socket(network::base_socket::ptr &&new_socket)
    {
        std::unique_lock<std::mutex> lk(mtx);
        socket = std::move(new_socket);
        lk.unlock();

        cv.notify_one(); 
    }

    void set_accept_timeout(std::chrono::seconds timeout) override {}

    [[nodiscard]] network::base_socket::ptr accept() override
    {
        while (true) {
            std::unique_lock<std::mutex> lk(mtx);
            cv.wait(lk);

            if (exit_flag) { break; }
            if (!socket) { continue; }

            return std::move(socket);
        }
        return network::base_socket::ptr();
    }

    void exit() { exit_flag = true;  cv.notify_all(); }
protected:
    std::atomic<bool> exit_flag{false};
    // A queue would result in oom unless we somehow rate-limit
    network::base_socket::ptr socket;
    std::mutex mtx;
    std::condition_variable cv;
};

}
