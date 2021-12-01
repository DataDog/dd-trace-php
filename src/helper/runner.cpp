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
    : cfg_(cfg), engine_pool_{std::make_shared<engine_pool>()},
      acceptor(acceptor_from_config(cfg))
{
}

runner::runner(const config::config &cfg, network::base_acceptor::ptr &&acceptor)
    : cfg_(cfg), engine_pool_{std::make_shared<engine_pool>()},
      acceptor(std::move(acceptor))
{
}

void runner::run() {
    try {
        while (running_) {
            auto socket = acceptor->accept();
            if (!socket) {
                SPDLOG_CRITICAL("Acceptor returned invalid socket. Bug.");
                break;
            }

            if (!running_) { break; }

            auto broker = std::make_unique<network::broker>(std::move(socket));

            SPDLOG_DEBUG("new client connected");
            auto runnable = [epool = engine_pool_, broker = std::move(broker)](
                                dds::worker::monitor &wm) mutable {
                client c(epool, std::move(broker));
                c.run(wm);
            };
            worker_pool_.launch(std::move(runnable));
        }
    } catch (const std::exception &e) {
        SPDLOG_ERROR("exception: {}", e.what());
    }
}

} // namespace dds
