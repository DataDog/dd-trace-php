#ifndef DDPROF_EVENT_HH
#define DDPROF_EVENT_HH

#include <sys/types.h>
#include <unistd.h>

#include <cstdint>
#include <unordered_set>
#include <vector>

#include <datadog/memhash.hh>

#include "chrono.hh"

namespace ddprof {

struct trace_context {
    uint64_t trace_id;
    uint64_t span_id;
};

constexpr bool operator==(trace_context a, trace_context b) {
    /* I don't know how long this link will stay good for, but it demonstrates
     * 3 ways to write this and shows the generated asm:
     *   https://godbolt.org/z/r98Kvn.
     * Across relevant compilers, this form is the most reliable for perf.
     */
    return !((a.trace_id ^ b.trace_id) | (b.span_id ^ b.span_id));
}

struct frame {
    uint64_t function_name;
    uint64_t filename;
    int64_t lineno;
};

struct event {
    uint64_t name;
    uint64_t thread_name;
    pid_t thread_id;
    system_clock::time_point system_time;
    steady_clock::time_point steady_time;
    // the most recent frame is at `frames[0]`
    std::vector<frame> frames;
    std::unordered_set<trace_context> trace_contexts;
};

} // namespace ddprof

#endif  // DDPROF_EVENT_HH
