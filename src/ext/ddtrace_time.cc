extern "C" {
#include "ddtrace_time.h"
}

#include <chrono>

using namespace std::chrono;

using uint64_nano = duration<int64_t, std::nano>;
using uint64_micro = duration<int64_t, std::micro>;
using int64_nano = duration<int64_t, std::nano>;

uint64_t ddtrace_monotonic_now_nsec() {
    auto now = steady_clock::now();
    auto since_epoch = now.time_since_epoch();
    auto interval = duration_cast<uint64_nano>(since_epoch);
    return interval.count();
}

uint64_t ddtrace_monotonic_now_usec() {
    auto now = steady_clock::now();
    auto since_epoch = now.time_since_epoch();
    auto interval = duration_cast<uint64_micro>(since_epoch);
    return interval.count();
}

int64_t ddtrace_realtime_now_nsec() {
    auto now = system_clock::now();
    auto since_epoch = now.time_since_epoch();
    auto interval = duration_cast<int64_nano>(since_epoch);
    return interval.count();
}
