#include <catch2/catch.hpp>
#include <condition_variable>

#include "ddprof/service.hh"

TEST_CASE("periodic_service basics", "[periodic_service]") {
    class test_service : public ddprof::periodic_service {
        public:
        std::mutex m;
        std::condition_variable cv;
        bool started;
        bool &destroyed;
        int &called;

        test_service(bool &d, int &i) noexcept : m{}, cv{}, started{false}, destroyed{d}, called{i} {}

        void periodic() override { ++called; }
        ~test_service() override { destroyed = true; }

        void on_start() noexcept override {
            std::lock_guard<std::mutex> lock{m};
            started = true;
            cv.notify_all();
        }
    };

    bool destroyed = false;
    int called = 0;
    {
        auto *service = new test_service(destroyed, called);
        auto interval = std::chrono::milliseconds(10);
        service->interval = interval;

        service->start();
        {
            std::unique_lock<std::mutex> lock(service->m);
            while (!service->started) {
                service->cv.wait(lock);
            }
        }

        service->stop();
        service->join();

        delete service;
    }

    REQUIRE(called);
    REQUIRE(destroyed);
}
