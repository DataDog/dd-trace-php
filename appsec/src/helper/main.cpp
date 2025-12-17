// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef _GNU_SOURCE
#    define _GNU_SOURCE
#endif

#include "config.hpp"
#include "runner.hpp"
#include "subscriber/waf.hpp"
#include <csignal>
#include <cstddef>
#include <cstdlib>
#include <functional>
#include <memory>
#include <spdlog/common.h>
#include <spdlog/sinks/basic_file_sink.h>
#include <spdlog/sinks/stdout_sinks.h>
#include <spdlog/spdlog.h>
#include <string_view>

extern "C" {
#include <fcntl.h>
#include <pthread.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <sys/types.h>
}

namespace {
constexpr std::chrono::seconds log_flush_interval{5};

std::atomic<bool> interrupted; // NOLINT
std::atomic<bool> finished;    // NOLINT
pthread_t thread_handle;

void *pthread_wrapper(void *arg)
{
    auto *func = static_cast<std::function<int8_t()> *>(arg);
    int8_t ret = 0;
    try {
        (*func)();
    } catch (const std::exception &e) {
        SPDLOG_ERROR("Exception in pthread wrapper: {}", e.what());
        ret = 1;
    } catch (...) {
        SPDLOG_ERROR("Unknown exception in pthread wrapper");
        ret = 1;
    }

    try {
        delete func; // NOLINT(cppcoreguidelines-owning-memory)
    } catch (...) {
        SPDLOG_ERROR("Unknown exception in func delete in pthread wrapper");
    }

    //  NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
    return reinterpret_cast<void *>(ret);
}

bool ensure_unique(const std::string &lock_path)
{
    // do not acquire the lock / assume we inherited it
    if (lock_path == "-") {
        return true;
    }

    /** The first helper process will create and exclusively lock the file */
    // NOLINTNEXTLINE
    const int fd = ::open(lock_path.c_str(), O_WRONLY | O_CREAT, 0744);
    if (fd == -1) {
        SPDLOG_INFO("Failed to open lock file: {}", lock_path);
        return false;
    }
    SPDLOG_DEBUG("Opened lock file {}: fd {}", lock_path, fd);

    int const res = ::flock(fd, LOCK_EX | LOCK_NB);
    // If we fail to obtain the lock, for whichever reason, assume we can't
    // run for now.
    if (res == -1) {
        ::close(fd);
        SPDLOG_INFO("Failed to get exclusive lock on file {}: errno {}",
            lock_path, errno);
        return false;
    }
    return true;
}

int appsec_helper_main_impl()
{
    dds::config::config const config{
        [](std::string_view key) -> std::optional<std::string_view> {
            // NOLINTNEXTLINE
            char const *value = std::getenv(key.data());
            if (value == nullptr) {
                return std::nullopt;
            }
            return std::string_view{value};
        }};

    std::shared_ptr<spdlog::logger> logger;
    bool stderr_fallback = false;
    try {
        logger = spdlog::basic_logger_mt(
            "ddappsec", std::string{config.log_file_path()}, false);
    } catch (std::exception &e) {
        // NOLINTNEXTLINE
        logger = spdlog::stderr_logger_mt("ddappsec");
        stderr_fallback = true;
    }

    spdlog::set_default_logger(logger);
    logger->set_pattern("[%Y-%m-%d %H:%M:%S.%e][%l][%t] %v");
    auto level = config.log_level();
    spdlog::set_level(level);
    spdlog::flush_on(level);

    if (stderr_fallback) {
        logger->warn("Failed to open log file {}, falling back to stderr",
            config.log_file_path());
    }

    dds::waf::initialise_logging(level);

    if (!ensure_unique(std::string{config.lock_file_path()})) {
        logger->warn("helper launched, but not unique, exiting");
        // There's another helper running
        return 1;
    }

    // block SIGUSR1 (only used to interrupt the runner)
    sigset_t mask;
    sigemptyset(&mask);
    sigaddset(&mask, SIGUSR1);
    if (auto err = pthread_sigmask(SIG_BLOCK, &mask, nullptr)) {
        SPDLOG_ERROR("Failed to block SIGUSR1: error number {}", err);
        return 1;
    }

    dds::remote_config::resolve_symbols();
    dds::runner::resolve_symbols();
    dds::service::resolve_symbols();

    auto runner = std::make_shared<dds::runner>(config, interrupted);
    SPDLOG_INFO("starting runner on new thread");

    auto thread_function = std::make_unique<std::function<int8_t()>>(
        [runner = std::move(runner)]() -> int8_t {
#ifdef __linux__
            pthread_setname_np(pthread_self(), "appsec_helper runner");
#elif defined(__APPLE__)
            pthread_setname_np("appsec_helper runner");
#endif
            runner->register_for_rc_notifications();

            runner->run();

            runner->unregister_for_rc_notifications();

            finished.store(true, std::memory_order_release);
            return 0;
        });

    // Set up pthread attributes with 8 MB stack size
    pthread_attr_t attr;
    if (int err = pthread_attr_init(&attr)) {
        SPDLOG_ERROR(
            "Failed to initialize pthread attributes: error number {}", err);
        return 1;
    }

    const dds::defer defer_attr_destroy{
        [&attr]() { pthread_attr_destroy(&attr); }};
    constexpr auto stack_size =
        static_cast<const size_t>(8 * 1024 * 1024); // 8 MB
    if (int err = pthread_attr_setstacksize(&attr, stack_size)) {
        SPDLOG_ERROR("Failed to set pthread stack size: error number {}", err);
        return 1;
    }

    if (int err = pthread_create(&thread_handle, &attr, pthread_wrapper,
            thread_function.release())) {
        SPDLOG_ERROR("Failed to create pthread: error number {}", err);
        return 1;
    }

    return 0;
}
} // namespace

extern "C" __attribute__((visibility("default"))) int
appsec_helper_main() noexcept
{
    try {
        return appsec_helper_main_impl();
    } catch (std::exception &e) {
        SPDLOG_ERROR("Unhandled exception: {}", e.what());
        return 2;
    } catch (...) {
        SPDLOG_ERROR("Unhandled exception");
        return 2;
    }
    return 0;
}

extern "C" __attribute__((visibility("default"))) int
appsec_helper_shutdown() noexcept
{
    interrupted.store(true, std::memory_order_release);
    pthread_kill(thread_handle, SIGUSR1);

    // wait up to 1 second for the runner to finish
    auto deadline = std::chrono::steady_clock::now() + std::chrono::seconds{1};
    bool had_finished = false;
    while (true) {
        if (!had_finished && finished.load(std::memory_order_acquire)) {
            SPDLOG_INFO("AppSec helper finished");
            had_finished = true;
        }

        // after finished is written to, we haven't necessarily  destroyed the
        // std::function and its captured variables. This needs to happen before
        // the helper shared library is unloaded by trampoline.c.
        // Wait for the joinable thread to actually exit (with a timeout).
        void *thr_exit_status;
#ifdef __APPLE__
        // macOS doesn't have pthread_tryjoin_np, so we check the deadline first
        // and then do a blocking join if the thread has finished
        if (had_finished) {
            // Thread has signaled finished, do a blocking join
            const int res = pthread_join(thread_handle, &thr_exit_status);
            if (res == 0) {
                SPDLOG_INFO("AppSec helper thread has exited");
                break;
            }
        }
#else
        const int res = pthread_tryjoin_np(thread_handle, &thr_exit_status);
        if (res == 0) {
            SPDLOG_INFO("AppSec helper thread has exited");
            break;
        }
#endif

        if (std::chrono::steady_clock::now() >= deadline) {
            // we need to call _exit() to avoid a segfault in the still running
            // helper threads after the helper shared library is unloaded by
            // trampoline.c
            SPDLOG_WARN("Could not finish AppSec helper before deadline. "
                        "Calling _exit().");
            _exit(EXIT_FAILURE); // NOLINT
            __builtin_unreachable();
        }
        std::this_thread::sleep_for(std::chrono::milliseconds{10}); // NOLINT
    }
    spdlog::shutdown();
    return 0;
}
