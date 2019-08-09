#ifndef DD_COMS_H
#define DD_COMS_H
#include <stdint.h>

#include "env_config.h"
#include "vendor_stdatomic.h"

#define DD_TRACE_COMS_STACK_SIZE (1024 * 1024 * 10)  // 10 MB
#define DD_TRACE_COMS_STACKS_BACKLOG_SIZE 10

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
} ddtrace_coms_state_t;

inline static BOOL_T ddtrace_coms_is_stack_unused(ddtrace_coms_stack_t *stack) {
    return atomic_load(&stack->refcount) == 0;
}

inline static BOOL_T ddtrace_coms_is_stack_free(ddtrace_coms_stack_t *stack) {
    return ddtrace_coms_is_stack_unused(stack) && atomic_load(&stack->bytes_written) == 0;
}

BOOL_T ddtrace_coms_rotate_stack(BOOL_T allocate_new);
ddtrace_coms_stack_t *ddtrace_coms_attempt_acquire_stack();

BOOL_T ddtrace_coms_buffer_data(uint32_t group_id, const char *data, size_t size);
BOOL_T ddtrace_coms_initialize();
void ddtrace_coms_shutdown();
size_t ddtrace_coms_read_callback(char *buffer, size_t size, size_t nitems, void *userdata);
void *ddtrace_init_read_userdata(ddtrace_coms_stack_t *stack);
void ddtrace_deinit_read_userdata(void *);
uint32_t ddtrace_coms_next_group_id();

void ddtrace_coms_free_stack();

#endif  // DD_COMS_H
