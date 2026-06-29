#ifndef DATADOG_THREADS_H
#define DATADOG_THREADS_H

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

#endif // DATADOG_THREADS_H
