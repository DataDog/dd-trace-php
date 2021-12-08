// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef RUNNER_HPP
#define RUNNER_HPP

#include <boost/asio.hpp>
#include <chrono>

#include "config.hpp"
#include "engine_pool.hpp"
#include "network/acceptor.hpp"
#include "network/socket.hpp"
#include "worker_pool.hpp"

namespace dds {

class runner {
public:
    explicit runner(const config::config &cfg);
    runner(const config::config &cfg, network::base_acceptor::ptr &&acceptor);
    runner(const runner &) = delete;
    runner &operator=(const runner &) = delete;
    runner(runner &&) = delete;
    runner &operator=(runner &&) = delete;
    ~runner() = default;

    void run() noexcept(false);

    void exit() { running_ = false; }

private:
    const config::config &cfg_;
    std::shared_ptr<engine_pool> engine_pool_;
    worker::pool worker_pool_;

    // Server variables
    network::base_acceptor::ptr acceptor_;
    std::chrono::minutes idle_timeout_;
    std::atomic<bool> running_{true};
};

} // namespace dds
#endif
