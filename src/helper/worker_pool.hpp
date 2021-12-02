// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include <atomic>
#include <chrono>
#include <condition_variable>
#include <functional>
#include <mutex>
#include <optional>
#include <queue>
#include <thread>
#include <utility>

namespace dds::worker {

class monitor {
public:
    class scope {
    public:
        // NOLINTNEXTLINE(google-runtime-references)
        explicit scope(monitor &m_): m(m_) { m.add_ref(); }
        ~scope() { if(valid) { m.del_ref(); } }
        scope(const scope&) = delete;
        scope& operator=(const scope&) = delete;
        scope(scope &&other) noexcept : m(other.m) { other.valid = false; }
        scope& operator=(scope&&) = delete;

        [[nodiscard]] monitor& get() const { return m; }
    protected:
        bool valid{true};
        monitor &m;
    };

    monitor() = default;
    ~monitor() {
        if (running_) {
            stop();
        }
    }

    monitor(const monitor&) = delete;
    monitor& operator=(const monitor&) = delete;
    monitor(monitor&&) = delete;
    monitor& operator=(monitor&&) = delete;

    void stop();
    void add_ref();
    void del_ref();
    [[nodiscard]] bool running() const { return running_; }
    [[nodiscard]] unsigned count() const { return count_; }
protected:
    std::mutex mtx_;
    std::atomic<bool> running_{true};
    unsigned count_{0};
    std::condition_variable cv_;
};

template<typename T>
class queue {
public:
    enum class push_mode : uint8_t {
        require_pending = 0,
        ignore_pending
    };

    queue() = default;
    ~queue() = default;
    queue(const queue&) = delete;
    queue& operator=(const queue&) = delete;
    queue(queue&&) noexcept = default;
    queue& operator=(queue&&) noexcept = default;

    bool push(T &data, push_mode mode = push_mode::require_pending) {
        {
            std::unique_lock<std::mutex> lock(mtx_);
            if (pending_ > 0 || mode == push_mode::ignore_pending) {
                q_.push(std::move(data));
            } else {
                return false;
            }
        }

        cv_.notify_one();
        return true;
    }

    template< class Rep, class Period >
    std::optional<T> pop(const std::chrono::duration<Rep, Period>& duration) {
        std::unique_lock<std::mutex> lock(mtx_);
        std::optional<T> retval;
        if (q_.empty()) {
            ++pending_;
            cv_.wait_for(lock, duration);
            --pending_;
            if (q_.empty()) { return retval; }
        }

        retval = std::move(q_.front());
        q_.pop();

        return retval;
    }

protected:
    std::mutex mtx_;
    std::condition_variable cv_;
    std::queue<T> q_;
    unsigned pending_{0};
};

using worker_queue = queue<std::function<void(monitor&)>>;

// Workers should require no extra storage within the pool, they are
// essentially detached threads and handle their own memory. The Monitor
// is used as a thread reference counter as well as a mechanism to signal
// when they should stop running.
class pool {
public:
    pool() = default;
    ~pool() {
        if (wm_.running()) {
            stop();
        }
    }

    pool(const pool &) = delete;
    pool &operator=(const pool &) = delete;
    pool(pool &&) = delete;
    pool &operator=(pool &&) = delete;

    bool launch(std::function<void(monitor&)> &&f);

    void stop() { wm_.stop(); }

    [[nodiscard]] unsigned worker_count() const { return wm_.count(); }
private:
    monitor wm_;
    worker_queue wq_;
};

} // namespace dds::worker
