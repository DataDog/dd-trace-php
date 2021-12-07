// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef WORKER_POOL_HPP
#define WORKER_POOL_HPP

#include <atomic>
#include <condition_variable>
#include <mutex>
#include <thread>
#include <utility>
#include <vector>

#include "scope.hpp"

namespace dds::worker {

class monitor {
  public:
    monitor() = default;
    [[nodiscard]] bool running() const { return running_; }

    void start() { running_ = true; }
    void stop();

    void add_reference();
    void delete_reference();

    // We could block but who has the time
    [[nodiscard]] unsigned count() const { return thread_count_; }

  protected:
    std::atomic<bool> running_{true};
    unsigned thread_count_{0};
    std::mutex m_;
    std::condition_variable cv_;
};

// Workers should require no extra storage within the pool, they are
// essentially detached threads and handle their own memory. The Monitor
// is used as a thread reference counter as well as a mechanism to signal
// when they should stop running.
class pool {
  public:
    pool() = default;
    ~pool()
    {
        if (wm_.running()) {
            stop();
        }
    }

    pool(const pool &) = delete;
    pool &operator=(const pool &) = delete;
    pool(pool &&) = delete;
    pool &operator=(pool &&) = delete;

    template <class Function, class... Args>
    bool launch(Function &&f, Args &&...args)
    {
        if (!wm_.running()) {
            return false;
        }
        std::thread t(std::forward<Function>(f), std::ref(wm_),
            std::forward<Args>(args)...);
        t.detach();
        return true;
    }

    [[nodiscard]] unsigned worker_count() const { return wm_.count(); }

    void stop() { wm_.stop(); }

  private:
    monitor wm_;
};

} // namespace dds::worker
#endif // WORKER_POOL_HPP
