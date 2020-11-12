extern "C" {
#include "profiler.h"
}

#include "stack_collector.hh"

using namespace ddprof;

struct ddtrace_profiler {
    class profiler impl;
};

ddtrace_profiler *ddtrace_profiler_create(void) {
    auto profiler = new ddtrace_profiler();

    profiler->impl.add_exporter(std::unique_ptr<exporter>(new pprof_exporter()));
    profiler->impl.add_collector(
        std::unique_ptr<collector>(new ddtrace::stack_collector(profiler->impl.get_recorder())));
    return profiler;
}

void ddtrace_profiler_start(ddtrace_profiler *profiler) { profiler->impl.start(); }
void ddtrace_profiler_stop(ddtrace_profiler *profiler) { profiler->impl.stop(); }
void ddtrace_profiler_join(ddtrace_profiler *profiler) { profiler->impl.join(); }
void ddtrace_profiler_destroy(ddtrace_profiler *profiler) { delete profiler; }
