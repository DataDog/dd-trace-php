#ifndef DDPROF_SERVICE_HH
#define DDPROF_SERVICE_HH

#include <atomic>
#include <chrono>
#include <mutex>
#include <thread>

namespace ddprof {

// a service is a thing that can be started, stopped, and joined
class service {
    std::atomic<bool> started{false};

    public:
    constexpr service() noexcept;
    virtual ~service();

    // services are not copyable
    service(const service &) = delete;
    service &operator=(const service &) = delete;

    virtual void start() = 0;
    virtual void stop() noexcept = 0;
    virtual void join() = 0;
};

class periodic_service : public service {
    protected:
    std::thread thread;
    std::atomic<bool> should_continue_{false};

    bool should_continue() noexcept;

    virtual void periodic();  // noexcept?
    virtual void on_start() noexcept;
    virtual void on_stop() noexcept;

    public:
    // positive numbers only
    std::chrono::nanoseconds interval{10};

    periodic_service();
    ~periodic_service() override;

    /* start and stop are final because they hold locks, and mixing inheritance
     * and locks has proven to be difficult.
     */
    void start() final;
    void stop() noexcept final;
    void join() final;
};

inline constexpr service::service() noexcept = default;
inline service::~service() = default;

inline periodic_service::periodic_service() = default;
inline periodic_service::~periodic_service() = default;

inline void periodic_service::periodic() {}
inline void periodic_service::on_start() noexcept {}
inline void periodic_service::on_stop() noexcept {}

inline bool periodic_service::should_continue() noexcept { return should_continue_; }

inline void periodic_service::start() {
    should_continue_ = true;
    thread = std::thread([this]() {
        on_start();
        while (should_continue()) {
            std::this_thread::sleep_for(interval);
            periodic();
        }
        on_stop();
    });
}

inline void periodic_service::stop() noexcept { should_continue_ = false; }

inline void periodic_service::join() { thread.join(); }

}  // namespace ddprof

#endif  // DDPROF_SERVICE_HH
