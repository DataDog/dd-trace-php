#ifndef DDTRACE_DDPROF_HH
#define DDTRACE_DDPROF_HH

#include "ddprof/collector.hh"
#include "ddprof/exporter.hh"
#include "ddprof/profiler.hh"
#include "ddprof/recorder.hh"
#include "ddprof/scheduler.hh"

extern "C" {
#include <datadog/arena.h>
};

namespace ddtrace {

// todo: move to periodic collector
class stack_collector : public ddprof::collector {
    class ddprof::recorder &recorder;
    std::mutex m;
    std::thread thread;
    bool running;

    pid_t thread_id;
    void collect();

    public:
    explicit stack_collector(ddprof::recorder &);

    void start() override;
    void stop() noexcept override;
    void join() override;
};
}  // namespace ddtrace

#endif  // DDTRACE_DDPROF_HH
