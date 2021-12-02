// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include "worker_pool.hpp"

using namespace std::chrono_literals;

namespace dds::worker {

void monitor::stop() {
    running_ = false;

    std::unique_lock<std::mutex> lock(mtx_);
    while (count_ > 0) {
        cv_.wait_for(lock, 100us);
    }
}

void monitor::add_ref() {
    std::unique_lock<std::mutex> lock(mtx_);
    ++count_;
}

void monitor::del_ref() {
    std::unique_lock<std::mutex> lock(mtx_);
    if (--count_ == 0 && !running_) {
        std::notify_all_at_thread_exit(cv_, std::move(lock));
    }
}

namespace {
// NOLINTNEXTLINE(google-runtime-references)
void work_handler(monitor::scope &&ws, worker_queue &wq) {
    monitor &wm =  ws.get();
    while (wm.running()) {
        auto runnable = wq.pop(100ms);
        if (!runnable || !wm.running()) { break; }
        runnable.value()(ws.get());
    }
}
} // namespace

bool pool::launch(std::function<void(monitor&)> &&f) {
    if (!wm_.running()) {
        return false;
    }

    if (!wq_.push(f)) {
        monitor::scope ws(wm_);
        wq_.push(f, worker_queue::push_mode::ignore_pending);
        std::thread(work_handler, std::move(ws), std::ref(wq_)).detach();
    }
    return true;
}


} // namespace dds::worker
