#ifndef DDPROF_PROFILER_HH
#define DDPROF_PROFILER_HH

#include <memory>
#include <vector>

#include "collector.hh"
#include "exporter.hh"
#include "scheduler.hh"

namespace ddprof {
class profiler {
    public:
    enum class status : unsigned {
        stopped = 0,
        running = 1,
    };

    profiler();

    class recorder &get_recorder();
    void add_collector(std::unique_ptr<class collector> collector);
    void add_exporter(std::unique_ptr<class exporter> exporter);

    void start() {
        std::lock_guard<std::mutex> lock{m};

        if (status == status::running) {
            return;
        }

        for (auto &collector : collectors) {
            collector->start();
        }
        scheduler.start();

        status = status::running;
    }

    void stop() {
        std::lock_guard<std::mutex> lock{m};
        scheduler.stop();

        for (auto it = collectors.rbegin(); it != collectors.rend(); ++it) {
            (*it)->stop();
        }

        status = status::stopped;
    }

    void join() {
        for (auto &collector : collectors) {
            collector->join();
        }
        scheduler.join();
    }

    private:
    std::vector<std::unique_ptr<collector>> collectors;
    std::vector<std::unique_ptr<exporter>> exporters;
    class recorder recorder;
    class scheduler scheduler;
    std::mutex m;
    enum status status;
};

inline profiler::profiler() :
    collectors{},
    exporters{},
    recorder{},
    scheduler(recorder, exporters, std::chrono::seconds{3}),
    m{},
    status{status::stopped} {}

inline class recorder &profiler::get_recorder() { return recorder; }

inline void profiler::add_collector(std::unique_ptr<class collector> collector) {
    collectors.emplace_back(std::move(collector));
}

inline void profiler::add_exporter(std::unique_ptr<class exporter> exporter) {
    exporters.emplace_back(std::move(exporter));
}

}  // namespace ddprof

#endif  // DDPROF_PROFILER_HH
