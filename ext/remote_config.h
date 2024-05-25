#ifndef DD_REMOTE_CONFIG_H
#define DD_REMOTE_CONFIG_H

#include "ddtrace_export.h"

void ddtrace_minit_remote_config(void);
void ddtrace_rinit_remote_config(void);
void ddtrace_rshutdown_remote_config(void);

DDTRACE_PUBLIC void ddtrace_set_all_thread_vm_interrupt(void);

#endif
