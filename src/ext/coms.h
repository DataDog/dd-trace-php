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
} dd_trace_coms_state_t;

inline static uint32_t dd_trace_coms_is_stack_unused(dd_trace_coms_stack_t *stack) {
    return atomic_load(&stack->refcount) == 0;
}

uint32_t dd_trace_coms_rotate_stack();
uint32_t dd_trace_coms_flush_data(const char *data, size_t size);
uint32_t dd_trace_coms_initialize();

#endif //DD_COMS_H
