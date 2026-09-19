#ifndef DD_REMOTE_CONFIG_H
#define DD_REMOTE_CONFIG_H

#include "datadog_export.h"

void datadog_minit_remote_config(void);
void datadog_mshutdown_remote_config(void);
void datadog_rinit_remote_config(void);
void datadog_rshutdown_remote_config(void);
void datadog_check_for_new_config_now(void);


#ifdef _WIN32
DATADOG_PUBLIC DWORD WINAPI datadog_set_all_thread_vm_interrupt(LPVOID unused);
#else
DATADOG_PUBLIC void datadog_set_all_thread_vm_interrupt(void);
#endif

#endif
