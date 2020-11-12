#ifndef DDPROF_SCHEDULER_HH
#define DDPROF_SCHEDULER_HH

#include <vector>

extern "C" {
#include <pthread.h>
};

#include "chrono.hh"
#include "exporter.hh"
#include "recorder.hh"
#include "service.hh"

namespace ddprof {

class scheduler : public periodic_service {
    using nanoseconds = std::chrono::nanoseconds;
    class recorder &recorder;
    std::vector<std::unique_ptr<exporter>> &exporters;
    std::chrono::nanoseconds configured_interval;
    system_clock::time_point last_export;

    public:
    scheduler(class recorder &r, std::vector<std::unique_ptr<exporter>> &exporters, nanoseconds cfg_interval) noexcept;
    ~scheduler() override;

    void on_start() noexcept override;
    void on_stop() noexcept override;

    void periodic() override;
};

inline scheduler::scheduler(class recorder &r, std::vector<std::unique_ptr<exporter>> &exporters,
                            nanoseconds cfg_interval) noexcept :
    recorder{r}, exporters{exporters}, configured_interval{cfg_interval} {}

inline void scheduler::periodic() {
    auto start_time = steady_clock::now();
    if (!exporters.empty()) {
        auto prev_last_export = last_export;
        last_export = system_clock::now();
        auto result = recorder.release();
        auto &event_table = result.first;
        auto &strings = result.second;
        for (auto &exporter : exporters) {
            (*exporter)(event_table, strings, prev_last_export, last_export);
        }
    }
    auto stop_time = steady_clock::now();
    interval = std::max(nanoseconds(0), configured_interval - (stop_time - start_time));
}

inline void scheduler::on_start() noexcept {
#if defined(__GLIBC__) || defined(__APPLE__)
    const char name[16] = "ddprof::sched";
#if defined(__GLIBC__)
    pthread_setname_np(pthread_self(), name);
#else
    pthread_setname_np(name);
#endif
#endif
    last_export = system_clock::now();
}

inline void scheduler::on_stop() noexcept { periodic(); }

inline scheduler::~scheduler() = default;

}  // namespace ddprof

#endif  // DDPROF_SCHEDULER_HH
