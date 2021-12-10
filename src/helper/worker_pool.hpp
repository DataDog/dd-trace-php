// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

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

class queue_consumer;

using runnable = std::function<void(queue_consumer &)>;

class queue_producer {
public:
    queue_producer() = default;
    ~queue_producer() { stop(); }

    queue_producer(const queue_producer &) = delete;
    queue_producer &operator=(const queue_producer &) = delete;
    queue_producer(queue_producer &&) = delete;
    queue_producer &operator=(queue_producer &&) = delete;

    [[nodiscard]] bool running()
    {
        return running_.load(std::memory_order_relaxed);
    }

    [[nodiscard]] unsigned ref_count() const { return rc_.count; }

    // NOLINTNEXTLINE(google-runtime-references)
    bool push(runnable &data);
    void wait();

    void stop()
    {
        running_.store(false, std::memory_order_relaxed);
        wait();
    }

protected:
    struct refcount {
        std::mutex mtx;
        std::condition_variable cv;
        unsigned count{0};
    } rc_;

    std::atomic<bool> running_{true};

    struct queue {
        std::mutex mtx;
        std::condition_variable cv;
        unsigned pending{0};
        std::queue<runnable> data;
    } q_;

    friend class queue_consumer;
};

class queue_consumer {
public:
    // NOLINTNEXTLINE(google-runtime-references)
    explicit queue_consumer(queue_producer &pq)
        : rc_(pq.rc_), running_(pq.running_), q_(pq.q_)
    {
        std::unique_lock<std::mutex> lock(rc_.mtx);
        ++rc_.count;
    }

    ~queue_consumer()
    {
        if (was_moved_from_) {
            return;
        }

        std::unique_lock<std::mutex> lock(rc_.mtx);
        if (--rc_.count == 0 && !running()) {
            std::notify_all_at_thread_exit(rc_.cv, std::move(lock));
        }
    }

    queue_consumer(const queue_consumer &other) = delete;
    queue_consumer &operator=(const queue_consumer &) = delete;

    queue_consumer(queue_consumer &&other) noexcept
        : rc_(other.rc_), running_(other.running_), q_(other.q_)
    {
        other.was_moved_from_ = true;
    }

    queue_consumer &operator=(queue_consumer &&) = delete;

    [[nodiscard]] bool running()
    {
        return running_.load(std::memory_order_relaxed);
    }

    template <class Rep, class Period>
    std::optional<runnable> pop(
        const std::chrono::duration<Rep, Period> &duration)
    {
        std::optional<runnable> retval;
        std::unique_lock<std::mutex> lock(q_.mtx);
        if (q_.data.empty()) {
            ++q_.pending;
            q_.cv.wait_for(lock, duration);
            --q_.pending;

            if (q_.data.empty()) {
                return retval;
            }
        }

        retval = std::move(q_.data.front());
        q_.data.pop();

        return retval;
    }

protected:
    bool was_moved_from_{false};
    queue_producer::refcount &rc_;
    std::atomic<bool> &running_;
    queue_producer::queue &q_;
};

// Workers should require no extra storage within the pool, they are
// essentially detached threads and handle their own memory. The Monitor
// is used as a thread reference counter as well as a mechanism to signal
// when they should stop running.
class pool {
public:
    pool() = default;
    ~pool() = default;
    pool(const pool &) = delete;
    pool &operator=(const pool &) = delete;
    pool(pool &&) = delete;
    pool &operator=(pool &&) = delete;

    bool launch(runnable &&f);

    void wait() { q_.wait(); }
    void stop() { q_.stop(); }

    [[nodiscard]] unsigned worker_count() const { return q_.ref_count(); }

private:
    queue_producer q_;
};

} // namespace dds::worker
