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

uint32_t dd_trace_flush_data(const char *data, size_t size);
uint32_t dd_trace_coms_initialize();
uint32_t dd_trace_coms_consumer();
uint32_t dd_spawn_test_writers();

#endif //DD_COMS_H
