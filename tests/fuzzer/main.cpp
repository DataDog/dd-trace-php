// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include <thread>
#include <runner.hpp>
#include <spdlog/sinks/stdout_color_sinks.h>
#include <spdlog/spdlog.h>
#include "mutators.hpp"
#include "network.hpp"
#include <iostream>

std::thread runner_thread;
std::unique_ptr<dds::runner> runner;
dds::fuzzer::acceptor *acceptor;
dds::config::config config;
std::function<decltype(RawMutator)> mutator;
auto logger = spdlog::stderr_color_mt("ddappsec");

void exit_handler()
{
    runner->exit();
    acceptor->exit();

    runner_thread.join();

    runner.reset(nullptr);
}

extern "C" int LLVMFuzzerInitialize(int *argc, char ***argv)
{
    std::atexit(exit_handler);

    config = dds::config::config(*argc, *argv);

    spdlog::set_default_logger(logger);
    logger->set_pattern("[%Y-%m-%d %H:%M:%S.%e][%l][%t] %v");
    spdlog::set_level(
        spdlog::level::from_str(config.get<std::string>("log_level")));

    std::string fuzz_mode;
    try {
        fuzz_mode = config.get<std::string>("fuzz-mode");
    } catch (...) {
        fuzz_mode = "raw";
    }

    std::cerr << "Fuzzing mode: " << fuzz_mode << std::endl;

    if (fuzz_mode == "body") {
        mutator = MessageBodyMutator;
    } else if (fuzz_mode == "raw") {
        mutator = RawMutator;
    } else if (fuzz_mode == "off") {
        mutator = NopMutator;
    }else {
        std::cerr << "Unsupported fuzzing mode, using raw mutator" << std::endl;
    }

    auto acceptor_ptr = std::make_unique<dds::fuzzer::acceptor>();
    acceptor = acceptor_ptr.get();
    runner = std::make_unique<dds::runner>(config, std::move(acceptor_ptr));

    runner_thread = std::move(std::thread([]() {
        runner->run(); 
    }));

    return 0;
}

extern "C" int LLVMFuzzerTestOneInput(const uint8_t* bytes, size_t size)
{
    acceptor->push_socket(std::make_unique<dds::fuzzer::raw_socket>(bytes, size));

    // TODO: configurable rate-limit and sequential mode

    return 0;
}

// The custom mutator:
extern "C" size_t LLVMFuzzerCustomMutator(uint8_t *Data, size_t Size,
                                          size_t MaxSize, unsigned int Seed)
{
    return mutator(Data, Size, MaxSize, Seed);
}
