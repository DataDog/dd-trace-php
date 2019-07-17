#ifndef DD_COMS_CURL_H
#define DD_COMS_CURL_H
#include "env_config.h"

BOOL_T ddtrace_coms_init_and_start_writer();
BOOL_T ddtrace_coms_trigger_writer_flush();
BOOL_T ddtrace_coms_on_request_finished();
BOOL_T ddtrace_coms_set_writer_send_on_flush(BOOL_T send);
BOOL_T ddtrace_in_writer_thread();
BOOL_T ddtrace_coms_threadsafe_rotate_stack(BOOL_T attempt_allocate_new);
BOOL_T ddtrace_coms_flush_shutdown_writer_synchronous();
BOOL_T ddtrace_coms_synchronous_flush(uint32_t timeout);
BOOL_T ddtrace_coms_on_pid_change();
void ddtrace_coms_setup_atexit_hook();
void ddtrace_coms_disable_atexit_hook();

#endif  // DD_COMS_CURL_H
