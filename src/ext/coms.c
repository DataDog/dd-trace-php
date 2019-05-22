#include <stdint.h>
#include <stddef.h>
#include <stdatomic.h>
#include <errno.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>
#include <curl/curl.h>

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
    dd_trace_coms_stack_t *current_stack = atomic_load(&dd_trace_coms_global_state.current_stack);

    for(int i = 0; i< DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        dd_trace_coms_stack_t *stack_tmp = dd_trace_coms_global_state.stacks[i];
        if (stack_tmp) {
            if (atomic_load(&stack_tmp->refcount) == 0 && atomic_load(&stack_tmp->bytes_written) == 0) {
                stack = stack_tmp;
                recycle_stack(stack_tmp);
                dd_trace_coms_global_state.stacks[i] = current_stack;
                current_stack = NULL;
                break;
            }
        }
    }

    //attempt to freeup stack storage
    gc_stacks();

    if (current_stack != NULL) {
        for(int i=0; i< DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
            if (!dd_trace_coms_global_state.stacks[i]){
                dd_trace_coms_global_state.stacks[i] = current_stack;
                current_stack = NULL;
            }
        }
    }

    // old current stack was stored so set a new stack
    if (current_stack == NULL) {
        if (!stack) {
            stack = new_stack();
        }

        atomic_store(&dd_trace_coms_global_state.current_stack, stack);
        return 0;
    }
    // if we couldn't store new stack i tem
    return ENOMEM;
}

uint32_t dd_trace_coms_flush_data(const char *data, size_t size){
    if (data && size == 0) {
        size = strlen(data);
    }

    if (size == 0) {
        return 0;
    }

    if (store_data(data, size) == 0) {
        return 1;
    } else {
        return 0;
    }
}

uint32_t dd_trace_coms_initialize(){
    init();
    curl_global_init();
    return 1;
}

uint32_t curl_ze_data_out() {
    dd_trace_coms_rotate_stack();
    dd_trace_coms_stack_t *stack = NULL;

    for(int i = 0; i< DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        dd_trace_coms_stack_t *stack_tmp = dd_trace_coms_global_state.stacks[i];
        if (atomic_load(&stack_tmp->refcount) == 0 && atomic_load(&stack_tmp->bytes_written) == 0) {
            stack = stack_tmp;
            break;
        }
    }
    CURL *curl = curl_easy_init();
    if(curl) {
        CURLcode res;
        curl_easy_setopt(curl, CURLOPT_URL, "http://localhost:8126/v0.4/traces");
        curl_easy_setopt(curl, CURLOPT_UPLOAD, 1);

        curl_easy_setopt(curl, CURLOPT_READDATA, hd_src);
        curl_easy_setopt(curl, CURLOPT_READFUNCTION, hd_src);

        res = curl_easy_perform(curl);
        curl_easy_cleanup(curl);
    }

    return 1;
}
