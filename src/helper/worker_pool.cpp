// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "worker_pool.hpp"

namespace dds::worker {

void monitor::stop()
{
    // While running is atomic, we ensure that no thread can unregister
    // before we wait on the condition variable.
    std::unique_lock<std::mutex> lock(m_);
    running_ = false;
    if (thread_count_ > 0) {
        cv_.wait(lock);
    }
}

void monitor::add_reference()
{
    std::lock_guard<std::mutex> lock(m_);
    ++thread_count_;
}

void monitor::delete_reference()
{
    bool notify = false;
    {
        std::lock_guard<std::mutex> lock(m_);
        if (--thread_count_ == 0) {
            notify = true;
        }
    }

    if (!running_ && notify) {
        cv_.notify_all();
    }
}

} // namespace dds::worker
