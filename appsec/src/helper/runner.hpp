// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <atomic>

#include "config.hpp"
#include "network/acceptor.hpp"
#include "service_manager.hpp"
#include "worker_pool.hpp"

namespace dds {

class runner : public std::enable_shared_from_this<runner> {
public:
    runner(const config::config &cfg, std::atomic<bool> &interrupted);
    runner(std::unique_ptr<network::base_acceptor> &&acceptor,
        std::atomic<bool> &interrupted);
    runner(const runner &) = delete;
    runner &operator=(const runner &) = delete;
    runner(runner &&) = delete;
    runner &operator=(runner &&) = delete;
    ~runner() = default;

    static void resolve_symbols();

    void run() noexcept(false);

    void register_for_rc_notifications();

    void unregister_for_rc_notifications();

    [[nodiscard]] bool interrupted() const
    {
        return interrupted_.load(std::memory_order_acquire);
    }

private:
    static std::shared_ptr<runner> RUNNER_FOR_NOTIFICATIONS;

    std::shared_ptr<service_manager> service_manager_;
    worker::pool worker_pool_;

    // Server variables
    std::unique_ptr<network::base_acceptor> acceptor_;
    std::atomic<bool> &interrupted_; // NOLINT
};

} // namespace dds
