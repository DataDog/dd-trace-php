#include <stdint.h>
#include <stddef.h>
#include <stdatomic.h>
#include <errno.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>

#include "coms.h"

dd_trace_coms_state_t dd_trace_coms_global_state = { .stacks = NULL, .current_stack = NULL };

static uint32_t store_data(const char *src, size_t size) {
    dd_trace_coms_stack_t *stack = atomic_load(&dd_trace_coms_global_state.current_stack);
    if (stack == NULL) {
        // no stack to save data to
        return ENOMEM;
    }

    size_t size_to_alloc = size + sizeof(size_t);

    atomic_fetch_add(&stack->refcount, 1);

    size_t position = atomic_fetch_add(&stack->position, size_to_alloc);
    if ((position + size_to_alloc) > stack->size) {
        //allocation failed
        atomic_fetch_sub(&stack->refcount, 1);
        return ENOMEM;
    }

    char *destination = stack->data + position;
    memcpy(destination, &size, sizeof(size_t));

    destination += sizeof(size_t);
    memcpy(destination, src, size);

    atomic_fetch_add(&stack->bytes_written, size_to_alloc);
    atomic_fetch_sub(&stack->refcount, 1);
    return 0;
}

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

void gc_stacks() {
    for(int i = 0; i < DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        dd_trace_coms_stack_t *stack = dd_trace_coms_global_state.stacks[i];

        if (stack) {
            if (dd_trace_coms_is_stack_unused(stack) && atomic_load(&stack->bytes_written) == 0) {
                dd_trace_coms_global_state.stacks[i] = NULL;
                free(stack);
            } else {
                stack->gc_cycles_count++;
            }
        }
    }
}

static void init() {
    dd_trace_coms_stack_t *stack = new_stack();
    if (!dd_trace_coms_global_state.stacks) {
        dd_trace_coms_global_state.stacks = calloc(DD_TRACE_COMS_STACKS_BACKLOG_SIZE, sizeof(dd_trace_coms_stack_t*));
    }

    atomic_store(&dd_trace_coms_global_state.current_stack, stack);
}

uint32_t dd_trace_coms_rotate_stack(){
    dd_trace_coms_stack_t *stack = NULL;
    dd_trace_coms_stack_t *old_stack = atomic_load(&dd_trace_coms_global_state.current_stack);

    for(int i = 0; i< DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        if (dd_trace_coms_global_state.stacks[i]) {
            if (atomic_load(&dd_trace_coms_global_state.stacks[i]->refcount) == 0) {
                stack = dd_trace_coms_global_state.stacks[i];
                recycle_stack(stack);
                dd_trace_coms_global_state.stacks[i] = old_stack;
                old_stack = NULL;
                break;
            }
        }
    }

    gc_stacks();

    if (old_stack != NULL) {
        for(int i=0; i< DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
            if (!dd_trace_coms_global_state.stacks[i]){
                dd_trace_coms_global_state.stacks[i] = old_stack;
                old_stack = NULL;
            }
        }
    }

    // old stack was stored
    if (old_stack == NULL) {
        if (!stack) {
            stack = new_stack();
        }

        atomic_store(&dd_trace_coms_global_state.current_stack, stack);
        return 0;
    }

    return ENOMEM;
}

uint32_t dd_trace_coms_flush_data(const char *data, size_t size){
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

