#ifndef DD_COMS_H
#define DD_COMS_H

#include <stdbool.h>
#include <stdint.h>

#include "vendor_stdatomic.h"

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

bool ddtrace_coms_rotate_stack(bool allocate_new, size_t min_size);
ddtrace_coms_stack_t *ddtrace_coms_attempt_acquire_stack();

bool ddtrace_coms_buffer_data(uint32_t group_id, const char *data, size_t size);
bool ddtrace_coms_initialize(void);
void ddtrace_coms_shutdown(void);
size_t ddtrace_coms_read_callback(char *buffer, size_t size, size_t nitems, void *userdata);
void *ddtrace_init_read_userdata(ddtrace_coms_stack_t *stack);
void ddtrace_deinit_read_userdata(void *);
uint32_t ddtrace_coms_next_group_id(void);
size_t ddtrace_read_userdata_get_total_groups(void *);

void ddtrace_coms_free_stack();

#endif  // DD_COMS_H
