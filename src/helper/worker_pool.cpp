// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "worker_pool.hpp"

using namespace std::chrono_literals;

namespace dds::worker {

namespace {

void work_handler(consumer_queue &&q, std::optional<runnable> &&opt_r)
{
    while (q.running() && opt_r) {
        opt_r.value()(q);
        opt_r = std::move(q.pop(60s));
    }
}

} // namespace

bool producer_queue::push(runnable &data)
{

    {
        std::unique_lock<std::mutex> lock(q_.mtx);
        if (q_.pending > 0) {
            q_.data.push(std::move(data));
        } else {
            return false;
        }
    }

    q_.cv.notify_one();
    return true;
}

void producer_queue::wait()
{
    std::unique_lock<std::mutex> lock(rc_.mtx);
    while (rc_.count > 0) {
        q_.cv.notify_all();
        rc_.cv.wait_for(lock, 100us);
    }
}

bool pool::launch(runnable &&f)
{
    if (!q_.running()) {
        return false;
    }

    if (!q_.push(f)) {
        std::thread(work_handler, consumer_queue(q_), std::move(f)).detach();
    }
    return true;
}

} // namespace dds::worker
