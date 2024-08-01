#ifndef DD_COMS_H
#define DD_COMS_H

#include <curl/curl.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <stdint.h>

typedef struct ddtrace_coms_stack_t {
    size_t size;
    _Atomic(size_t) position;
    _Atomic(size_t) bytes_written;
    _Atomic(int32_t) refcount;
    char *data;
} ddtrace_coms_stack_t;

typedef struct ddtrace_coms_state_t {
    // This is the current stack where new incoming payloads are written to.
    _Atomic(ddtrace_coms_stack_t *) current_stack;
    ddtrace_coms_stack_t *tmp_stack;
    /* An array of `DD_TRACE_AGENT_MAX_BACKLOG_SIZE` stacks. Different stacks can have different size.
     * Stacks that are accessed through this array are not for write. They are either empty, if their content has been
     * sent and the stack is ready to be reused, or filled with data ready to be sent at the next writer iteration.
     */
    ddtrace_coms_stack_t **stacks;
    _Atomic(uint32_t) next_group_id;
    /* The size of the `current_stack`. Note that `current_stack` and `stack_size` are not changed in a 'transaction',
     * so, with the current implementation, they have to be manually kept in sync.
     */
    atomic_size_t stack_size;

    /*
     * The initial buffer size, from DD_TRACE
     */
    size_t initial_stack_size;
    /*
     * The maximum payload size, from DD_TRACE_AGENT_MAX_PAYLOAD_SIZE
     */
    size_t max_payload_size;
    /*
     * The maximum backlog size, from DD_TRACE_AGENT_MAX_BACKLOG_SIZE
     */
    size_t max_backlog_size;

    /* Whether to send fallback telemetry. */
    bool bgs_fallback_telemetry;
    char initial_service_name[100];
    
    char test_session_token[100];
} ddtrace_coms_state_t;

inline bool ddtrace_coms_is_stack_unused(ddtrace_coms_stack_t *stack) { return atomic_load(&stack->refcount) == 0; }

inline bool ddtrace_coms_is_stack_free(ddtrace_coms_stack_t *stack) {
    return ddtrace_coms_is_stack_unused(stack) && atomic_load(&stack->bytes_written) == 0;
}

/* Is called by the PHP thread to buffer a payload in order to send it. It is non-blocking on the request to the agent.
 */
bool ddtrace_coms_buffer_data(uint32_t group_id, const char *data, size_t size);
bool ddtrace_coms_minit(size_t initial_stack_size, size_t max_payload_size, size_t max_backlog_size, char *bgs_fallback_telemetry_service);
void ddtrace_coms_mshutdown(void);
void ddtrace_coms_curl_shutdown(void);
void ddtrace_coms_rshutdown(void);
uint32_t ddtrace_coms_next_group_id(void);
void ddtrace_coms_set_test_session_token(const char *token, size_t token_len);

bool ddtrace_coms_init_and_start_writer(void);
bool ddtrace_coms_trigger_writer_flush(void);
bool ddtrace_coms_set_writer_send_on_flush(bool send);
bool ddtrace_in_writer_thread(void);
bool ddtrace_coms_flush_shutdown_writer_synchronous(void);
bool ddtrace_coms_synchronous_flush(uint32_t timeout);
bool ddtrace_coms_on_pid_change(void);

// Kills the background sender thread
void ddtrace_coms_kill_background_sender(void);
void ddtrace_coms_clean_background_sender_after_fork(void);

/* exposed for testing {{{ */
uint32_t ddtrace_coms_test_writers(void);
uint32_t ddtrace_coms_test_consumer(void);
uint32_t ddtrace_coms_test_msgpack_consumer(void);
/* }}} */

/* exposed for diagnostics {{{ */
void ddtrace_curl_set_hostname(CURL *curl);
void ddtrace_curl_set_timeout(CURL *curl);
void ddtrace_curl_set_connect_timeout(CURL *curl);
/* }}} */

extern struct ddog_ShmHandle *ddtrace_coms_agent_config_handle;

#endif  // DD_COMS_H
