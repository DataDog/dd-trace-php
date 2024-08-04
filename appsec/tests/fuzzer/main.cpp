// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.

#include "mutators.hpp"
#include "network.hpp"
#include <cstdlib>
#include <iostream>
#include <runner.hpp>
#include <spdlog/sinks/stdout_color_sinks.h>
#include <spdlog/spdlog.h>
#include <thread>

dds::fuzzer::acceptor *acceptor;
std::function<decltype(RawMutator)> mutator; // NOLINT

extern "C" int LLVMFuzzerRunDriver(
    int *argc, char ***argv, int (*UserCb)(const uint8_t *Data, size_t Size));

extern "C" int LLVMFuzzerInitialize(int * /*argc*/, char *** /*argv*/)
{
    return 0;
}

extern "C" int LLVMFuzzerTestOneInput(const uint8_t *bytes, size_t size)
{
    acceptor->push_socket(
        std::make_unique<dds::fuzzer::raw_socket>(bytes, size));
    return 0;
}

// The custom mutator:
extern "C" size_t LLVMFuzzerCustomMutator(
    uint8_t *Data, size_t Size, size_t MaxSize, unsigned int Seed)
{
    return mutator(Data, Size, MaxSize, Seed);
}

namespace {

class ext_config : public dds::config::config {
    static auto args_to_map(int argc, char **argv)
    {
        auto kv{std::map<std::string_view, std::string_view>{}};
        for (int i = 1; i < argc; ++i) {
            std::string_view arg(argv[i]);
            if (arg.size() < 2 || arg.substr(0, 2) != "--") {
                // Not an option, weird
                continue;
            }
            arg.remove_prefix(2);

            // Check if the option has an assignment
            auto pos = arg.find('=');
            if (pos != std::string::npos) {
                kv[arg.substr(0, pos)] = arg.substr(pos + 1);
                continue;
            }

            // Check the next argument
            if ((i + 1) < argc) {
                const std::string_view value(argv[i + 1]);
                if (arg.size() < 2 || arg.substr(0, 2) != "--") {
                    // Not an option, so we assume it's a value
                    kv[arg] = value;
                    // Skip on next iteration
                    ++i;
                    continue;
                }
            }

            // If the next argument is an option or this is the last argument,
            // we assume it's just a modifier.
            kv[arg] = std::string_view();
        }

        return kv;
    }

    std::function<std::optional<std::string_view>(std::string_view)>
    make_resolve_func(int argc, char **argv)
    {
        auto args = args_to_map(argc, argv);

        // also go for this side effect
        auto fm = args.find("fuzz-mode");
        if (fm != args.end()) {
            fuzz_mode = fm->second;
        } else {
            fuzz_mode = "raw";
        }

        return
            [args = std::move(args)](
                std::string_view env_name) -> std::optional<std::string_view> {
                auto it = args.find(map_to_arg_name(env_name));
                if (it == args.end()) {
                    if (env_name == env_log_file_path) {
                        return {"/dev/stderr"};
                    }
                    return std::nullopt;
                }
                return it->second;
            };
    }

    static std::string_view map_to_arg_name(std::string_view env_name)
    {
        if (env_name == env_socket_file_path) {
            return "socket_path";
        }
        if (env_name == env_lock_file_path) {
            return "lock_path";
        }
        if (env_name == env_log_file_path) {
            return "log_path";
        }
        if (env_name == env_log_level) {
            return "log_level";
        }
        return "";
    }

public:
    std::string_view fuzz_mode;

    ext_config(int argc, char **argv)
        : dds::config::config{make_resolve_func(argc, argv)}
    {
        auto args = args_to_map(argc, argv);

        // also go for this side effect
        auto fm = args.find("fuzz-mode");
        if (fm != args.end()) {
            fuzz_mode = fm->second;
        } else {
            fuzz_mode = "raw";
        }
    }
};
} // namespace

int main(int argc, char **argv)
{
    ext_config const config{argc, argv};

    auto logger = spdlog::stderr_color_mt("ddappsec");
    spdlog::set_default_logger(logger);
    logger->set_pattern("[%Y-%m-%d %H:%M:%S.%e][%l][%t] %v");
    spdlog::set_level(config.log_level());

    std::string fuzz_mode;
    try {
        fuzz_mode = config.fuzz_mode;
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
    } else {
        std::cerr << "Unsupported fuzzing mode, using raw mutator" << std::endl;
    }

    auto acceptor_ptr = std::make_unique<dds::fuzzer::acceptor>();
    acceptor = acceptor_ptr.get();
    std::atomic<bool> interrupted;
    dds::runner runner{config, std::move(acceptor_ptr), interrupted};

    std::thread runner_thread([&runner] { runner.run(); });

    int result = LLVMFuzzerRunDriver(&argc, &argv, LLVMFuzzerTestOneInput);

    interrupted.store(true, std::memory_order_release);
    acceptor->exit();

    runner_thread.join();

    return result;
}
