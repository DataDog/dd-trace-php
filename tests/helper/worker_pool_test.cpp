// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include <worker_pool.hpp>
#include "common.hpp"

namespace {
void thread_handler(dds::worker::monitor &wm, bool &running, std::mutex &m,
    std::condition_variable &cv) {

    ASSERT_TRUE(wm.running());

    {
        std::unique_lock<std::mutex> lock(m);
        running = true;
    }

    cv.notify_one();

    while (wm.running()) { std::this_thread::sleep_for(100us); }
}

} // namespace

namespace dds {

TEST(WorkerPoolTest, PoolLaunchZeroWorkers) {
    worker::pool wp;
    EXPECT_EQ(wp.worker_count(), 0);

    wp.stop();
    EXPECT_EQ(wp.worker_count(), 0);
}

TEST(WorkerPoolTest, PoolLaunchOneWorker) {
    worker::pool wp;
    EXPECT_EQ(wp.worker_count(), 0);

    std::mutex m;
    std::condition_variable cv;
    bool running = false;
    wp.launch(
        [&running = running, &m = m, &cv = cv](dds::worker::monitor &wm) {
            thread_handler(wm, running, m, cv);
        }
    );

    {
        std::unique_lock<std::mutex> lock(m);
        while (!running) { cv.wait(lock); }
    }
    EXPECT_EQ(wp.worker_count(), 1);

    wp.stop();
    EXPECT_EQ(wp.worker_count(), 0);
}

TEST(WorkerPoolTest, PoolLaunchNWorkers) {
    worker::pool wp;
    EXPECT_EQ(wp.worker_count(), 0);

    std::mutex m;
    std::condition_variable cv;
    bool running = false;

    for (int i = 0; i < 10; i++) {
        wp.launch(
            [&running = running, &m = m, &cv = cv](dds::worker::monitor &wm) {
                thread_handler(wm, running, m, cv);
            }
        );

        {
            std::unique_lock<std::mutex> lock(m);
            while (!running) { cv.wait(lock); }
        }
        EXPECT_EQ(wp.worker_count(), i + 1);
        running = false;
    }
    EXPECT_EQ(wp.worker_count(), 10);

    wp.stop();
    EXPECT_EQ(wp.worker_count(), 0);
}

} // namespace dds
