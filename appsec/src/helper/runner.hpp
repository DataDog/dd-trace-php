// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <atomic>
#include <chrono>
#include <cstdint>

#include "config.hpp"
#include "network/acceptor.hpp"
#include "network/socket.hpp"
#include "service_manager.hpp"
#include "worker_pool.hpp"

namespace dds {

class runner {
public:
    runner(const config::config &cfg, std::atomic<bool> &interrupted);
    runner(const config::config &cfg, network::base_acceptor::ptr &&acceptor,
        std::atomic<bool> &interrupted);
    runner(const runner &) = delete;
    runner &operator=(const runner &) = delete;
    runner(runner &&) = delete;
    runner &operator=(runner &&) = delete;
    ~runner() = default;

    void run() noexcept(false);

    [[nodiscard]] bool interrupted() const
    {
        return interrupted_.load(std::memory_order_acquire);
    }

private:
    const config::config &cfg_; // NOLINT
    std::shared_ptr<service_manager> service_manager_;
    worker::pool worker_pool_;

    // Server variables
    network::base_acceptor::ptr acceptor_;
    std::atomic<bool> &interrupted_; // NOLINT
};

} // namespace dds
