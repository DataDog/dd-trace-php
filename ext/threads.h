#ifndef DD_THREADS_H
#define DD_THREADS_H

#include <TSRM/TSRM.h>
#include <Zend/zend_types.h>

#if ZTS
extern HashTable ddtrace_tls_bases;
extern MUTEX_T ddtrace_threads_mutex;

void ddtrace_thread_mshutdown(void);
void ddtrace_thread_ginit(void);
void ddtrace_thread_gshutdown(void);
#endif

#endif // DD_THREADS_H
