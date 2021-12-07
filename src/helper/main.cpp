// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include "config.hpp"
#include "runner.hpp"
#include "subscriber/waf.hpp"
#include <csignal>
#include <fcntl.h>
#include <iostream>
#include <spdlog/sinks/stdout_color_sinks.h>
#include <spdlog/spdlog.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <sys/types.h>

std::atomic<bool> exit_signal = false;
std::atomic<dds::runner *> global_runner = nullptr;

void signal_handler(int signum) {
    SPDLOG_INFO("Got signal {}", signum);

    dds::runner *runner = global_runner.load();
    runner->exit();
    exit_signal = true;
}

bool ensure_unique(const dds::config::config &config) {
    auto lock_path = config.get<std::string_view>("lock_path");

    // do not acquire the lock / assume we inherited it
    if (lock_path == "-") {
        return true;
    }

    /** The first helper process will create and exclusively lock the file */
    // NOLINTNEXTLINE
    int fd = ::open(lock_path.data(), O_WRONLY | O_CREAT, 0744);
    if (fd == -1) {
        return false;
    }

    int res = ::flock(fd, LOCK_EX | LOCK_NB);
    // If we fail to obtain the lock, for whichever reason, assume we can't
    // run for now.
    return res != -1;
}

int main(int argc, char *argv[]) {
    std::signal(SIGTERM, signal_handler);
    std::signal(SIGPIPE, SIG_IGN); // NOLINT
    dds::config::config config(argc, argv);

    auto logger = spdlog::stderr_color_mt("ddappsec");
    spdlog::set_default_logger(logger);
    logger->set_pattern("[%Y-%m-%d %H:%M:%S.%e][%l][%t] %v");

    auto level = spdlog::level::from_str(config.get<std::string>("log_level"));
    spdlog::set_level(level);
    dds::waf::initialise_logging(level);

    if (!ensure_unique(config)) {
        logger->warn("helper launched, but not unique, exiting");
        // There's another helper running
        return 0;
    }

    try {
        SPDLOG_INFO("starting runner");
        dds::runner runner(config);
        global_runner = &runner;
        if (!exit_signal) {
            runner.run();
        }
    } catch (const std::exception &e) {
        SPDLOG_ERROR("exception: {}", e.what());
    }

    return 0;
}
