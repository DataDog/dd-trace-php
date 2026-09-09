#include "profiling_cpu_time.h"

#include <components/log/log.h>
#ifdef PROFILING
#include <profiling/src/lifecycle.h>
#endif

#include "datadog.h"
#include "ffi_utils.h"

#if defined(PROFILING) && defined(__linux__)
ZEND_EXTERN_MODULE_GLOBALS(datadog);

#include <errno.h>
#include <string.h>
#include <sys/syscall.h>
#include <unistd.h>

#ifndef sigev_notify_thread_id
#define sigev_notify_thread_id _sigev_un._tid
#endif

#define DATADOG_CPU_PROFILING_INTERVAL_NS 10000000L

static struct sigaction datadog_previous_sigrtmin_action;
static bool datadog_sigrtmin_handler_installed;

static bool datadog_is_our_sigrtmin_action(const struct sigaction *action);

static void datadog_chain_sigrtmin(int signal, siginfo_t *siginfo, void *context) {
    if (datadog_previous_sigrtmin_action.sa_flags & SA_SIGINFO) {
        if (datadog_previous_sigrtmin_action.sa_sigaction) {
            datadog_previous_sigrtmin_action.sa_sigaction(signal, siginfo, context);
        }
        return;
    }

    if (datadog_previous_sigrtmin_action.sa_handler == SIG_IGN) {
        return;
    }
    if (datadog_previous_sigrtmin_action.sa_handler == SIG_DFL) {
        sigaction(signal, &datadog_previous_sigrtmin_action, NULL);
        raise(signal);
        return;
    }
    datadog_previous_sigrtmin_action.sa_handler(signal);
}

static void datadog_sigrtmin_handler(int signal, siginfo_t *siginfo, void *context) {
#if ZTS
    if (!tsrm_is_managed_thread()) {
        datadog_chain_sigrtmin(signal, siginfo, context);
        return;
    }
#endif

    if (siginfo && siginfo->si_code == SI_TIMER && DATADOG_G(profiling_cpu_timer_created) &&
        siginfo->si_value.sival_ptr == (void *)&DATADOG_G(profiling_cpu_timer)) {
        if (DATADOG_G(profiling_cpu_timer_armed)) {
            uint32_t samples = 1;
            if (siginfo->si_overrun > 0) {
                samples += (uint32_t)siginfo->si_overrun;
            }
            ddog_php_prof_mark_cpu_time_samples(samples);
#if PHP_VERSION_ID >= 80200
            zend_atomic_bool_store_ex(&EG(vm_interrupt), 1);
#elif PHP_VERSION_ID >= 70100
            EG(vm_interrupt) = 1;
#else
            DATADOG_G(zai_vm_interrupt) = 1;
#endif
        }
        return;
    }

    datadog_chain_sigrtmin(signal, siginfo, context);
}

static bool datadog_is_our_sigrtmin_action(const struct sigaction *action) {
    return (action->sa_flags & SA_SIGINFO) && action->sa_sigaction == datadog_sigrtmin_handler;
}

static void datadog_profiling_cpu_time_disarm(zend_datadog_globals *globals) {
    if (!globals->profiling_cpu_timer_created) {
        return;
    }

    globals->profiling_cpu_timer_armed = 0;
    struct itimerspec current = {0};
    if (timer_gettime(globals->profiling_cpu_timer, &current) == 0 && (current.it_value.tv_sec != 0 || current.it_value.tv_nsec != 0)) {
        globals->profiling_cpu_timer_remaining = current.it_value;
    }
    struct itimerspec disarmed = {0};
    if (timer_settime(globals->profiling_cpu_timer, 0, &disarmed, NULL) != 0) {
        LOG(WARN, "Failed to disarm profiling CPU timer: %s", strerror(errno));
    }
}

bool datadog_profiling_cpu_time_minit(void) {
    struct sigaction current = {0};
    if (sigaction(SIGRTMIN, NULL, &current) != 0) {
        LOG(ERROR, "Failed to inspect SIGRTMIN before installing profiling CPU handler: %s", strerror(errno));
        return false;
    }

    // MINIT may run again during an Apache graceful restart without unloading us.
    if (datadog_is_our_sigrtmin_action(&current)) {
        datadog_sigrtmin_handler_installed = true;
        return true;
    }

    struct sigaction action = {0};
    action.sa_flags = SA_SIGINFO | SA_RESTART;
    action.sa_sigaction = datadog_sigrtmin_handler;
    sigemptyset(&action.sa_mask);
    if (sigaction(SIGRTMIN, &action, &datadog_previous_sigrtmin_action) != 0) {
        LOG(ERROR, "Failed to install profiling CPU SIGRTMIN handler: %s", strerror(errno));
        return false;
    }
    datadog_sigrtmin_handler_installed = true;
    return true;
}

void datadog_profiling_cpu_time_mshutdown(void) {
    if (!datadog_sigrtmin_handler_installed) {
        return;
    }

    struct sigaction current = {0};
    if (sigaction(SIGRTMIN, NULL, &current) == 0 && datadog_is_our_sigrtmin_action(&current)) {
        sigaction(SIGRTMIN, &datadog_previous_sigrtmin_action, NULL);
    }
    datadog_sigrtmin_handler_installed = false;
}

bool datadog_profiling_cpu_time_rinit(bool enabled) {
    if (!enabled) {
        return true;
    }

    pid_t pid = getpid();
    if (!DATADOG_G(profiling_cpu_timer_created) || DATADOG_G(profiling_cpu_timer_pid) != pid) {
        // POSIX timers are not inherited across fork; discard the inherited identifier.
        DATADOG_G(profiling_cpu_timer_created) = 0;
        DATADOG_G(profiling_cpu_timer_armed) = 0;
        DATADOG_G(profiling_cpu_timer_remaining) = (struct timespec){.tv_sec = 0, .tv_nsec = DATADOG_CPU_PROFILING_INTERVAL_NS};

        struct sigevent event = {0};
        event.sigev_notify = SIGEV_THREAD_ID;
        event.sigev_signo = SIGRTMIN;
        event.sigev_value.sival_ptr = (void *)&DATADOG_G(profiling_cpu_timer);
        event.sigev_notify_thread_id = (pid_t)syscall(SYS_gettid);
        if (timer_create(CLOCK_THREAD_CPUTIME_ID, &event, &DATADOG_G(profiling_cpu_timer)) != 0) {
            LOG(ERROR, "Failed to create profiling CPU timer: %s", strerror(errno));
            return false;
        }
        DATADOG_G(profiling_cpu_timer_pid) = pid;
        DATADOG_G(profiling_cpu_timer_created) = 1;
    }

    struct itimerspec periodic = {
        .it_interval = {.tv_sec = 0, .tv_nsec = DATADOG_CPU_PROFILING_INTERVAL_NS},
        .it_value = DATADOG_G(profiling_cpu_timer_remaining),
    };
    DATADOG_G(profiling_cpu_timer_armed) = 1;
    if (timer_settime(DATADOG_G(profiling_cpu_timer), 0, &periodic, NULL) != 0) {
        DATADOG_G(profiling_cpu_timer_armed) = 0;
        LOG(ERROR, "Failed to arm profiling CPU timer: %s", strerror(errno));
        return false;
    }
    return true;
}

void datadog_profiling_cpu_time_rshutdown(void) { datadog_profiling_cpu_time_disarm(DATADOG_GLOBALS_PTR()); }

void datadog_profiling_cpu_time_gshutdown(zend_datadog_globals *globals) {
    if (!globals->profiling_cpu_timer_created) {
        return;
    }
    datadog_profiling_cpu_time_disarm(globals);
    timer_delete(globals->profiling_cpu_timer);
    globals->profiling_cpu_timer_created = 0;
    globals->profiling_cpu_timer_pid = 0;
}

#else

bool datadog_profiling_cpu_time_minit(void) { return true; }
void datadog_profiling_cpu_time_mshutdown(void) {}
bool datadog_profiling_cpu_time_rinit(bool enabled) {
    UNUSED(enabled);
    return true;
}
void datadog_profiling_cpu_time_rshutdown(void) {}
void datadog_profiling_cpu_time_gshutdown(zend_datadog_globals *globals) { UNUSED(globals); }

#endif
