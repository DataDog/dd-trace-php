#ifndef DDPROF_STEADY_CLOCK_HH
#define DDPROF_STEADY_CLOCK_HH

#include <chrono>
#include <ctime>

namespace ddprof {

/* Standard clocks can have a major performance overhead:
 *   https://gcc.gnu.org/legacy-ml/gcc-help/2019-07/msg00069.html
 *
 * So, we work around this by defining our own clock types. It mimics the
 * standard <chrono> types to the best of my poor knowledge. ~Levi
 */
class steady_clock {
    public:
    using duration = std::chrono::nanoseconds;
    using rep = duration::rep;
    using period = duration::period;
    using time_point = std::chrono::time_point<steady_clock, duration>;

    constexpr static bool is_steady = true;
    static time_point now() noexcept;
};

class system_clock {
    public:
    using duration = std::chrono::nanoseconds;
    using rep = duration::rep;
    using period = duration::period;
    using time_point = std::chrono::time_point<system_clock, duration>;

    constexpr static bool is_steady = true;
    static time_point now() noexcept;
};

inline steady_clock::time_point steady_clock::now() noexcept {
    using std::chrono::nanoseconds;
    using std::chrono::seconds;
#ifdef __APPLE__
    /* Apple recommends CLOCK_UPTIME_RAW over mach_absolute_time:
     * https://developer.apple.com/documentation/kernel/1462446-mach_absolute_time
     * This does limit portability to older Macs so if anyone actually hits an
     * issue, please file a bug report.
     */
    clockid_t clock = CLOCK_UPTIME_RAW;
    return time_point(nanoseconds(clock_gettime_nsec_np(clock)));
#else
    clockid_t clock = CLOCK_MONOTONIC;
    struct timespec tp;
    if (clock_gettime(clock, &tp) != 0) {
        // no idea what to do with a clock failure :/
        return time_point(nanoseconds(0));
    }
    return time_point(seconds(tp.tv_sec) + nanoseconds(tp.tv_nsec));
#endif
}

inline system_clock::time_point system_clock::now() noexcept {
    using std::chrono::nanoseconds;
    using std::chrono::seconds;
    struct timespec tp;
    if (clock_gettime(CLOCK_REALTIME, &tp) != 0) {
        // no idea what to do with a clock failure :/
        return time_point(nanoseconds(0));
    }
    return time_point(seconds(tp.tv_sec) + nanoseconds(tp.tv_nsec));
}

}  // namespace ddprof

#endif  // DDPROF_STEADY_CLOCK_HH
