// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "runner.hpp"

#include "client.hpp"
#include "subscriber/waf.hpp"
#include <csignal>
#include <cstdio>
#include <spdlog/spdlog.h>
#include <stdexcept>
#include <sys/stat.h>

namespace dds {

namespace {
network::base_acceptor::ptr acceptor_from_config(const config::config &cfg)
{
    std::string_view const sock_path{cfg.socket_file_path()};
    if (sock_path.size() >= 4 && sock_path.substr(0, 3) == "fd:") {
        auto rest{sock_path.substr(3)};
        int const fd = std::stoi(std::string{rest}); // can throw
        struct stat statbuf {};
        int const res = fstat(fd, &statbuf);
        if (res == -1 || !S_ISSOCK(statbuf.st_mode)) {
            throw std::invalid_argument{
                "fd specified on config is invalid or no socket"};
        }
        return std::make_unique<network::local::acceptor>(fd);
    }

    return std::make_unique<network::local::acceptor>(sock_path);
}

void block_sigusr1()
{
    sigset_t mask;
    sigemptyset(&mask);
    sigaddset(&mask, SIGUSR1);
    if (pthread_sigmask(SIG_UNBLOCK, &mask, nullptr) != 0) {
        throw std::runtime_error{
            "Failed to block SIGUSR1: errno " + std::to_string(errno)};
    }
}

void unblock_sigusr1()
{
    sigset_t mask;
    sigemptyset(&mask);
    sigaddset(&mask, SIGUSR1);
    if (pthread_sigmask(SIG_UNBLOCK, &mask, nullptr) != 0) {
        throw std::runtime_error{
            "Failed to unblock SIGUSR1: errno " + std::to_string(errno)};
    }
}

void handle_sigusr1()
{
    // the signal handler need not do anything (just interrupt accept())
    struct sigaction alarmer = {};
    alarmer.sa_handler = [](int) {};
    if (sigaction(SIGUSR1, &alarmer, nullptr) < 0) {
        throw std::runtime_error{
            "Failed to set SIGUSR1 handler: errno " + std::to_string(errno)};
    }
}
} // namespace

runner::runner(const config::config &cfg, std::atomic<bool> &interrupted)
    : runner{cfg, acceptor_from_config(cfg), interrupted}
{}

runner::runner(const config::config &cfg,
    network::base_acceptor::ptr &&acceptor, std::atomic<bool> &interrupted)
    : cfg_(cfg), service_manager_{std::make_shared<service_manager>()},
      acceptor_(std::move(acceptor)), interrupted_{interrupted}
{
    try {
        acceptor_->set_accept_timeout(1min);
    } catch (const std::exception &e) {
        // Not a critical error, we should continue
        SPDLOG_WARN("Failed to set runner timeout: {}", e.what());
    }
}

void runner::run()
{
    try {
        SPDLOG_INFO("Runner running");
        handle_sigusr1();

        while (!interrupted()) {
            unblock_sigusr1();
            network::base_socket::ptr socket = acceptor_->accept();
            block_sigusr1();

            if (!socket) {
                continue; // interrupted / timeout
            }

            if (interrupted()) {
                break;
            }

            const std::shared_ptr<client> c =
                std::make_shared<client>(service_manager_, std::move(socket));

            SPDLOG_DEBUG("new client connected");

            worker_pool_.launch(
                [c](worker::queue_consumer &q) mutable { c->run(q); });
        }
    } catch (const std::exception &e) {
        SPDLOG_ERROR("exception: {}", e.what());
    }

    SPDLOG_INFO("Runner exiting, stopping pool");
    worker_pool_.stop();
    SPDLOG_INFO("Pool stopped");
}

} // namespace dds
