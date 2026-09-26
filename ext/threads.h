#ifndef DATADOG_THREADS_H
#define DATADOG_THREADS_H

#include <stdbool.h>
#include <stdint.h>
#include <TSRM/TSRM.h>
#include <Zend/zend_types.h>

#if ZTS
extern HashTable datadog_tls_bases;
extern MUTEX_T datadog_threads_mutex;

void datadog_thread_ginit(void);
void datadog_thread_gshutdown(void);
#else

// Taken from TSRM.h
# ifdef _WIN32
#  define MUTEX_T CRITICAL_SECTION *
# else
#  include <pthread.h>
#  define MUTEX_T pthread_mutex_t *
# endif

TSRM_API MUTEX_T tsrm_mutex_alloc(void);
TSRM_API void tsrm_mutex_free(MUTEX_T mutexp);
TSRM_API int tsrm_mutex_lock(MUTEX_T mutexp);
TSRM_API int tsrm_mutex_unlock(MUTEX_T mutexp);
#endif

#ifdef __linux__
#include <stdatomic.h>

struct ddog_SignalFlush;
typedef int32_t (*datadog_raw_clone_fn)(const struct ddog_SignalFlush *, bool);

int datadog_clone_thread(datadog_raw_clone_fn fn, void *stack_top, int flags,
                         const struct ddog_SignalFlush *arg, bool terminate_process, _Atomic(int) *tid);
long datadog_raw_syscall6(
    long number,
    long arg1,
    long arg2,
    long arg3,
    long arg4,
    long arg5,
    long arg6
);
#endif

#endif // DATADOG_THREADS_H
