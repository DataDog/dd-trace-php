#include "limiter.h"

#include <pthread.h>
#include <unistd.h>
#include <sys/mman.h>

// clang-format Off

#define NANOSECONDS_PER_SECOND 1000000000

typedef struct {
    /* limit from configuration DD_TRACE_RATE_LIMIT */
    uint32_t limit;
    struct {
        /* beginning of window */
        uint64_t open;
        /* last hit inside this window */
        uint64_t current;
        /* total count for this window */
        uint32_t samples;
        /* allowed count for this window */
        uint32_t allowed;
        /* limit (tokens) for this window */
        uint32_t limit;
        /* rate for the last window that passed */
        uint32_t rate;
    } window;

    pthread_mutex_t lock;
} ddtrace_limiter;

static ddtrace_limiter* dd_limiter;

static inline uint64_t ddtrace_limiter_clock() {
    struct timespec ts;

    if (clock_gettime(CLOCK_MONOTONIC, &ts) != 0) {
        /* According to man page, this may only happen
            if monotonic clock is not supported on this system. */
        return 0;
    }

    return (ts.tv_sec * NANOSECONDS_PER_SECOND) + /* seconds as nanoseconds */
            ts.tv_nsec;                           /* plus remaining nanoseconds */
}

void ddtrace_limiter_create() {
    uint32_t limit = (uint32_t) get_global_DD_TRACE_RATE_LIMIT();

    if (!limit) {
        return;
    }

    /*
     We share the limiter among forks (ie, forks need to write this memory), this requires that we map the memory as anonymous and shared
    */
    dd_limiter = mmap(NULL, sysconf(_SC_PAGESIZE), PROT_WRITE, MAP_SHARED|MAP_ANONYMOUS, -1, 0);

    if ( dd_limiter == MAP_FAILED) {
        dd_limiter = NULL;
        return;
    }

    dd_limiter->limit = limit;
    dd_limiter->window.open = ddtrace_limiter_clock();
    dd_limiter->window.limit = limit;

    pthread_mutexattr_t mattr;

    /*
     Mutex must have PROCESS_SHARED set to be usable among forks
    */
    pthread_mutexattr_init(&mattr);
    pthread_mutexattr_setpshared(
        &mattr, PTHREAD_PROCESS_SHARED);
    pthread_mutex_init(&dd_limiter->lock, &mattr);
    pthread_mutexattr_destroy(&mattr);
}

bool ddtrace_limiter_active() {
    if (!dd_limiter) {
        return false;
    }

    return true;
}

bool ddtrace_limiter_allow() {
    ZEND_ASSERT(dd_limiter);

    pthread_mutex_lock(&dd_limiter->lock);

    dd_limiter->window.current  = ddtrace_limiter_clock();

    if (dd_limiter->window.current > (dd_limiter->window.open + NANOSECONDS_PER_SECOND)) {
        /* window passed, reset */

        /* store current window rate for effective rate calculation */
        dd_limiter->window.rate = dd_limiter->window.samples ?
            (double) dd_limiter->window.allowed / dd_limiter->window.samples : 1;

        /* move window */
        dd_limiter->window.open = dd_limiter->window.current;
        dd_limiter->window.samples = 0;
        dd_limiter->window.allowed = 0;
    }

    if (dd_limiter->window.samples++ < dd_limiter->window.limit) {
        dd_limiter->window.allowed++;

        pthread_mutex_unlock(&dd_limiter->lock);
        return true;
    }

    pthread_mutex_unlock(&dd_limiter->lock);
    return false;
}

double ddtrace_limiter_rate() {
    double effective = 0;

    pthread_mutex_lock(&dd_limiter->lock);

    if (!dd_limiter->window.rate ||
        !dd_limiter->window.samples) {
        /* no previous window, or samples */
        effective = (double) dd_limiter->window.allowed / dd_limiter->window.samples;
    } else {
        effective = (double) (
            (double) dd_limiter->window.allowed / dd_limiter->window.samples +  /* current rate */
            dd_limiter->window.rate                                             /* previous window rate */
        ) / 2.0;                                                                /* spread over last two windows */
    }

    pthread_mutex_unlock(&dd_limiter->lock);

    return effective;
}

void ddtrace_limiter_destroy() {
    if (!dd_limiter) {
        return;
    }

    pthread_mutex_destroy(&dd_limiter->lock);

    munmap(dd_limiter, sysconf(_SC_PAGESIZE));

    dd_limiter = NULL;
}

// clang-format on
