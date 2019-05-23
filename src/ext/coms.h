#ifndef DD_COMS_H
#define DD_COMS_H
#include <stdint.h>
#include <stdatomic.h>

#define DD_TRACE_COMS_STACK_SIZE (1024*1024*5) // 5 MB
#define DD_TRACE_COMS_STACKS_BACKLOG_SIZE 10

typedef struct dd_trace_coms_stack_t {
    size_t size;
    _Atomic(size_t) position;
    _Atomic(size_t) bytes_written;
    _Atomic(int32_t) refcount;
    int32_t gc_cycles_count;
    char *data;
} dd_trace_coms_stack_t;

typedef struct dd_trace_coms_state_t {
    _Atomic(dd_trace_coms_stack_t *)current_stack;
    dd_trace_coms_stack_t **stacks;
    _Atomic(uint32_t) next_group_id;
} dd_trace_coms_state_t;

inline static uint32_t dd_trace_coms_is_stack_unused(dd_trace_coms_stack_t *stack) {
    return atomic_load(&stack->refcount) == 0;
}

uint32_t dd_trace_coms_rotate_stack();
dd_trace_coms_stack_t *dd_trace_coms_attempt_acquire_stack();

uint32_t dd_trace_coms_flush_data(uint32_t group_id, const char *data, size_t size);
uint32_t dd_trace_coms_initialize();
size_t dd_trace_coms_read_callback(char *buffer, size_t size, size_t nitems, void *userdata);
void *dd_trace_init_read_userdata(dd_trace_coms_stack_t *stack);
uint32_t dd_trace_coms_next_group_id();

uint32_t curl_ze_data_out();

#endif //DD_COMS_H
