#ifndef DD_COMS_CURL_H
#define DD_COMS_CURL_H
#include "env_config.h"

BOOL_T ddtrace_coms_init_and_start_writer();
BOOL_T ddtrace_coms_trigger_writer_flush();
BOOL_T ddtrace_coms_on_request_finished();
BOOL_T ddtrace_coms_set_writer_send_on_flush(BOOL_T send);

#endif  // DD_COMS_CURL_H
