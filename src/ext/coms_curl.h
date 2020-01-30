#ifndef DD_COMS_CURL_H
#define DD_COMS_CURL_H

#include <curl/curl.h>
#include <stdbool.h>
#include <stdint.h>

#include "vendor_stdatomic.h"

extern atomic_uintptr_t memoized_agent_curl_headers;

bool ddtrace_coms_init_and_start_writer(void);
bool ddtrace_coms_trigger_writer_flush(void);
void ddtrace_coms_on_request_finished(void);
bool ddtrace_coms_set_writer_send_on_flush(bool send);
bool ddtrace_in_writer_thread(void);
bool ddtrace_coms_threadsafe_rotate_stack(bool attempt_allocate_new, size_t min_size);
bool ddtrace_coms_flush_shutdown_writer_synchronous(void);
bool ddtrace_coms_synchronous_flush(uint32_t timeout);
bool ddtrace_coms_on_pid_change(void);
void ddtrace_coms_setup_atexit_hook(void);
void ddtrace_coms_disable_atexit_hook(void);
void ddtrace_coms_mshutdown(void);

#endif  // DD_COMS_CURL_H
