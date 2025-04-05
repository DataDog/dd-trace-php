// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "worker_pool.hpp"

using namespace std::chrono_literals;

namespace dds::worker {

namespace {

// NOLINTNEXTLINE(cppcoreguidelines-rvalue-reference-param-not-moved)
void work_handler(queue_consumer &&q, std::optional<runnable> &&opt_r)
{
    while (q.running() && opt_r) {
        // NOLINTNEXTLINE(bugprone-unchecked-optional-access)
        opt_r.value()(q);
        opt_r = q.pop(60s);
    }
}

} // namespace

bool queue_producer::push(runnable &data)
{
    {
        std::unique_lock<std::mutex> const lock(q_.mtx);
        if (q_.pending > 0) {
            q_.data.push(std::move(data));
        } else {
            return false;
        }
    }

    q_.cv.notify_one();
    return true;
}

void queue_producer::wait()
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
        std::thread(work_handler, queue_consumer(q_), std::move(f)).detach();
    }
    return true;
}

} // namespace dds::worker
