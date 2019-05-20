#include <stdint.h>
#include <stddef.h>
#include <stdatomic.h>
#include <errno.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>

#include "curling.h"

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

dd_trace_coms_state_t dd_trace_global_state = {0};

uint32_t store_data(const char *src, size_t size) {
    dd_trace_coms_stack_t *stack = atomic_load(&dd_trace_global_state.current_stack);
    if (stack == NULL) {
        // no stack to save data to
        return ENOMEM;
    }

    size_t size_to_alloc = size + sizeof(size_t);

    atomic_fetch_add(&stack->refcount, 1);

    size_t position = atomic_fetch_add(&stack->position, size_to_alloc);
    if ((position + size_to_alloc) > stack->size) {
        //allocation failed
        return ENOMEM;
    }

    char *destination = stack->data + position;
    memcpy(destination, &size, sizeof(size_t));

    destination += sizeof(size_t);
    memcpy(destination, src, size);

    atomic_fetch_add(&stack->bytes_written, size_to_alloc);
    atomic_fetch_add(&stack->refcount, - 1);
    return 0;
}

#define DD_TRACE_COMS_STACK_SIZE 1024*1024*5 // 5 MB
#define DD_TRACE_COMS_STACKS_BACKLOG_SIZE 10

dd_trace_coms_stack_t *new_stack() {
    dd_trace_coms_stack_t *stack = calloc(1, sizeof(dd_trace_coms_stack_t));
    stack->size = DD_TRACE_COMS_STACK_SIZE;
    stack->data = calloc(1, stack->size);

    return stack;
}

void free_stack(dd_trace_coms_stack_t *stack) {
    free(stack->data);
    free(stack);
}

void recycle_stack(dd_trace_coms_stack_t *stack) {
    char *data = stack->data;
    size_t size = stack->size;

    memset(stack, 0, sizeof(dd_trace_coms_stack_t));
    memset(data, 0, size);

    stack->data = data;
    stack->size = size;
}

inline static uint32_t is_stack_unused(dd_trace_coms_stack_t *stack) {
    return atomic_load(&stack->refcount) == 0;
}

void gc_stacks() {
    for(int i = 0; i < DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        dd_trace_coms_stack_t *stack = dd_trace_global_state.stacks[i];

        if (stack) {
            if (is_stack_unused(stack) && atomic_load(&stack->bytes_written) == 0) {
                dd_trace_global_state.stacks[i] = NULL;
                free(stack);
            } else {
                stack->gc_cycles_count++;
            }
        }
    }
}

static void init() {
    dd_trace_coms_stack_t *stack = new_stack();
    if (!dd_trace_global_state.stacks) {
        dd_trace_global_state.stacks = calloc(DD_TRACE_COMS_STACKS_BACKLOG_SIZE, sizeof(dd_trace_coms_stack_t*));
    }

    atomic_store(&dd_trace_global_state.current_stack, stack);
}

uint32_t rotate_stack(){
    dd_trace_coms_stack_t *stack = NULL;
    dd_trace_coms_stack_t *old_stack = atomic_load(&dd_trace_global_state.current_stack);

    for(int i = 0; i< DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        if (dd_trace_global_state.stacks[i]) {
            if (atomic_load(&dd_trace_global_state.stacks[i]->refcount) == 0) {
                stack = dd_trace_global_state.stacks[i];
                recycle_stack(stack);
                dd_trace_global_state.stacks[i] = old_stack;
                old_stack = NULL;
                break;
            }
        }
    }

    gc_stacks();

    if (old_stack != NULL) {
        for(int i=0; i< DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
            if (!dd_trace_global_state.stacks[i]){
                dd_trace_global_state.stacks[i] = old_stack;
                old_stack = NULL;
            }
        }
    }

    // old stack was stored
    if (old_stack == NULL) {
        if (!stack) {
            stack = new_stack();
        }

        atomic_store(&dd_trace_global_state.current_stack, stack);
        return 0;
    }

    return ENOMEM;
}

uint32_t dd_trace_flush_data(const char *data, size_t size){
    if (data && size == 0) {
        size = strlen(data);
    }

    if (store_data(data, size) == 0) {
        return 1;
    } else {
        return 0;
    }
}

uint32_t dd_trace_coms_initialize(){
    init();
    return 1;
}

uint32_t dd_trace_coms_consumer(){
    if (rotate_stack() != 0) {
        //error
    }

    for(int i = 0; i< DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        dd_trace_coms_stack_t *stack = dd_trace_global_state.stacks[i];
        if (!stack || !is_stack_unused(stack)){
            continue;
        }

        size_t position = 0;
        size_t bytes_written = atomic_load(&stack->bytes_written);
        while (position < bytes_written) {
            size_t size = 0;
            memcpy(&size, stack->data + position, sizeof(size_t));

            position += sizeof(size_t);
            if (size == 0) {

            }
            char *data = stack->data + position;
            printf("s: %lu > %.*s \n", size, 4, data);
            printf("s: %lu\n", atomic_load(&stack->bytes_written));
            position += size;
            printf("%lu>> \n", position);
        }
    }

    return 1;
}
