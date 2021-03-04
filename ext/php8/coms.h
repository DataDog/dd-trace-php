#ifndef DD_COMS_H
#define DD_COMS_H

#include <curl/curl.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <stdint.h>

#define DDTRACE_COMS_STACK_MAX_SIZE (1024u * 1024u * 10u)      // 10 MiB
#define DDTRACE_COMS_STACK_HALF_MAX_SIZE (1024u * 1024u * 5u)  // 5 MiB
#define DDTRACE_COMS_STACK_INITIAL_SIZE (1024u * 128u)         // 128 KiB
#define DDTRACE_COMS_STACKS_BACKLOG_SIZE 10

typedef struct ddtrace_coms_stack_t {
    size_t size;
    _Atomic(size_t) position;
    _Atomic(size_t) bytes_written;
    _Atomic(int32_t) refcount;
    char *data;
} ddtrace_coms_stack_t;

typedef struct ddtrace_coms_state_t {
    _Atomic(ddtrace_coms_stack_t *) current_stack;
    ddtrace_coms_stack_t *tmp_stack;
    ddtrace_coms_stack_t **stacks;
    _Atomic(uint32_t) next_group_id;
    atomic_size_t stack_size;
} ddtrace_coms_state_t;

inline bool ddtrace_coms_is_stack_unused(ddtrace_coms_stack_t *stack) { return atomic_load(&stack->refcount) == 0; }

inline bool ddtrace_coms_is_stack_free(ddtrace_coms_stack_t *stack) {
    return ddtrace_coms_is_stack_unused(stack) && atomic_load(&stack->bytes_written) == 0;
}

bool ddtrace_coms_buffer_data(uint32_t group_id, const char *data, size_t size);
bool ddtrace_coms_minit(void);
void ddtrace_coms_mshutdown(void);
void ddtrace_coms_curl_shutdown(void);
void ddtrace_coms_rshutdown(void);
uint32_t ddtrace_coms_next_group_id(void);

bool ddtrace_coms_init_and_start_writer(void);
bool ddtrace_coms_trigger_writer_flush(void);
bool ddtrace_coms_set_writer_send_on_flush(bool send);
bool ddtrace_in_writer_thread(void);
bool ddtrace_coms_flush_shutdown_writer_synchronous(void);
bool ddtrace_coms_synchronous_flush(uint32_t timeout);
bool ddtrace_coms_on_pid_change(void);

/* exposed for testing {{{ */
uint32_t ddtrace_coms_test_writers(void);
uint32_t ddtrace_coms_test_consumer(void);
uint32_t ddtrace_coms_test_msgpack_consumer(void);
/* }}} */

/* exposed for diagnostics {{{ */
char *ddtrace_agent_url(void);
void ddtrace_curl_set_hostname(CURL *curl);
void ddtrace_curl_set_timeout(CURL *curl);
void ddtrace_curl_set_connect_timeout(CURL *curl);
/* }}} */

#endif  // DD_COMS_H
