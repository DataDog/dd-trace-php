// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include "runner.hpp"

#include "client.hpp"
#include "engine_pool.hpp"
#include "subscriber/waf.hpp"
#include <cstdio>
#include <spdlog/spdlog.h>
#include <stdexcept>
#include <sys/stat.h>


namespace dds {

namespace {
network::base_acceptor::ptr acceptor_from_config(const config::config &cfg)
{
    auto value{cfg.get<std::string_view>("socket_path")};
    if (value.size() >= 4 && value.substr(0, 3) == "fd:") {
        auto rest{value.substr(3)};
        int fd = std::stoi(std::string{rest}); // can throw
        struct stat statbuf{};
        int res = fstat(fd, &statbuf);
        if (res == -1 || !S_ISSOCK(statbuf.st_mode)) {
            throw std::invalid_argument{
                "fd specified on config is invalid or no socket"};
        }
        return std::make_unique<network::local::acceptor>(fd);
    }

    return std::make_unique<network::local::acceptor>(value);
}
} // namespace

runner::runner(const config::config &cfg)
    : runner(cfg, acceptor_from_config(cfg)) {}

runner::runner(const config::config &cfg, network::base_acceptor::ptr &&acceptor)
    : cfg_(cfg), engine_pool_{std::make_shared<engine_pool>()},
      acceptor_(std::move(acceptor)),
      idle_timeout_(cfg.get<unsigned>("runner_idle_timeout"))
{
    try {
        acceptor_->set_accept_timeout(1min);
    } catch (const std::exception &e) {
        // Not a critical error, we should continue
        SPDLOG_WARN("Failed to set runner timeout: {}", e.what());
    }
}

void runner::run() {
    try {
        auto last_not_idle = std::chrono::steady_clock::now();
        SPDLOG_INFO("Running");
        while (running_) {
            network::base_socket::ptr socket;
            try {
                socket = acceptor_->accept();
            } catch (const timeout_error &e) {
                // If there are clients running, we don't
                if (worker_pool_.worker_count() > 0) {
                    // We are not idle, update
                    last_not_idle = std::chrono::steady_clock::now();
                    continue;
                }

                auto elapsed = std::chrono::steady_clock::now() - last_not_idle;
                if (elapsed >= idle_timeout_) {
                    SPDLOG_INFO("Runner idle for {} minutes, exiting", 
                        idle_timeout_.count());
                    break;
                }

                continue;
            }

            if (!socket) {
                SPDLOG_CRITICAL("Acceptor returned invalid socket. Bug.");
                break;
            }

            if (!running_) { break; }

            std::shared_ptr<client> c =
                std::make_shared<client>(engine_pool_, std::move(socket));

            SPDLOG_DEBUG("new client connected");
            dds::worker::runnable r(
                [c](dds::worker::consumer_queue &q) mutable {
                    while (q.running()) { 
                        if (!c->run_once()) { break; }
                    }
                    // NOLINTNEXTLINE(bugprone-lambda-function-name)
                    SPDLOG_DEBUG("Finished handling client");
                }
            );

            worker_pool_.launch(std::move(r));
            last_not_idle = std::chrono::steady_clock::now();
        }
    } catch (const std::exception &e) {
        SPDLOG_ERROR("exception: {}", e.what());
    }
}

} // namespace dds
