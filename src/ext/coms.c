#include "coms.h"

#include <errno.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "coms_curl.h"
#include "configuration.h"
#include "env_config.h"
#include "mpack.h"
#include "vendor_stdatomic.h"

typedef uint32_t group_id_t;

#define GROUP_ID_PROCESSED (1UL << 31UL)

#ifndef __clang__
// disable checks since older GCC is throwing false errors
#pragma GCC diagnostic push
#pragma GCC diagnostic ignored "-Wmissing-field-initializers"
ddtrace_coms_state_t ddtrace_coms_global_state = {{0}};
#pragma GCC diagnostic pop
#else  //__clang__
ddtrace_coms_state_t ddtrace_coms_global_state = {.stacks = NULL};
#endif

static inline BOOL_T is_memory_pressure_high() {
    ddtrace_coms_stack_t *stack = atomic_load(&ddtrace_coms_global_state.current_stack);
    if (stack) {
        int64_t used = (((double)atomic_load(&stack->position) / (double)stack->size) * 100);
        return (used > get_dd_trace_beta_high_memory_pressure_percent());
    } else {
        return FALSE;
    }
}

static uint32_t store_data(group_id_t group_id, const char *src, size_t size) {
    ddtrace_coms_stack_t *stack = atomic_load(&ddtrace_coms_global_state.current_stack);
    if (stack == NULL) {
        // no stack to save data to
        return ENOMEM;
    }

    size_t size_to_alloc = size + sizeof(size_t) + sizeof(group_id_t);

    atomic_fetch_add(&stack->refcount, 1);

    size_t position = atomic_fetch_add(&stack->position, size_to_alloc);
    if ((position + size_to_alloc) > stack->size) {
        // allocation failed
        atomic_fetch_sub(&stack->refcount, 1);
        return ENOMEM;
    }

    memcpy(stack->data + position, &size, sizeof(size_t));
    position += sizeof(size_t);

    memcpy(stack->data + position, &group_id, sizeof(group_id_t));
    position += sizeof(group_id_t);

    memcpy(stack->data + position, src, size);

    atomic_fetch_add(&stack->bytes_written, size_to_alloc);
    atomic_fetch_sub(&stack->refcount, 1);
    return 0;
}

static inline ddtrace_coms_stack_t *new_stack() {
    ddtrace_coms_stack_t *stack = calloc(1, sizeof(ddtrace_coms_stack_t));
    stack->size = DD_TRACE_COMS_STACK_SIZE;
    stack->data = calloc(1, stack->size);

    return stack;
}

void ddtrace_coms_free_stack(ddtrace_coms_stack_t *stack) {
    free(stack->data);
    free(stack);
}

static inline void recycle_stack(ddtrace_coms_stack_t *stack) {
    char *data = stack->data;
    size_t size = stack->size;

    memset(stack, 0, sizeof(ddtrace_coms_stack_t));
    memset(data, 0, size);

    stack->data = data;
    stack->size = size;
}

BOOL_T ddtrace_coms_initialize() {
    ddtrace_coms_stack_t *stack = new_stack();
    if (!ddtrace_coms_global_state.stacks) {
        ddtrace_coms_global_state.stacks = calloc(DD_TRACE_COMS_STACKS_BACKLOG_SIZE, sizeof(ddtrace_coms_stack_t *));
    }

    atomic_store(&ddtrace_coms_global_state.next_group_id, 1);
    atomic_store(&ddtrace_coms_global_state.current_stack, stack);

    return TRUE;
}

void ddtrace_coms_shutdown() {
    ddtrace_coms_stack_t *current_stack = atomic_load(&ddtrace_coms_global_state.current_stack);
    if (current_stack) {
        if (current_stack->data) {
            free(current_stack->data);
        }
        free(current_stack);
    }
    if (ddtrace_coms_global_state.stacks) {
        free(ddtrace_coms_global_state.stacks);
        ddtrace_coms_global_state.stacks = NULL;
    }
}

#if 0
static void printf_stack_info(ddtrace_coms_stack_t *stack) {
    printf("stack (%p) refcount: (%d) bytes_written: (%lu)\n", stack, atomic_load(&stack->refcount),
           atomic_load(&stack->bytes_written));
}
#endif

static void unsafe_store_or_discard_stack(ddtrace_coms_stack_t *stack) {
    for (int i = 0; i < DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        ddtrace_coms_stack_t *stack_tmp = ddtrace_coms_global_state.stacks[i];
        if (stack_tmp == stack) {
            return;
        }

        if (stack_tmp == NULL) {
            ddtrace_coms_global_state.stacks[i] = stack;
            return;
        }
    }

    ddtrace_coms_free_stack(stack);
}

static inline void unsafe_cleanup_dirty_stack_area() {
    ddtrace_coms_stack_t *current_stack = atomic_load(&ddtrace_coms_global_state.current_stack);
    if (!ddtrace_coms_global_state.tmp_stack) {
        return;
    }

    if (ddtrace_coms_global_state.tmp_stack != current_stack) {
        ddtrace_coms_stack_t *stack = ddtrace_coms_global_state.tmp_stack;

        atomic_store(&stack->refcount, 0);
        unsafe_store_or_discard_stack(stack);
    }
    ddtrace_coms_global_state.tmp_stack = NULL;
}

static void unsafe_store_or_swap_current_stack_for_empty_stack() {
    unsafe_cleanup_dirty_stack_area();

    // store the temp variable if we ever need to recover it
    ddtrace_coms_stack_t **current_stack = &ddtrace_coms_global_state.tmp_stack;

    *current_stack = atomic_load(&ddtrace_coms_global_state.current_stack);

    if (*current_stack && ddtrace_coms_is_stack_free(*current_stack)) {
        *current_stack = NULL;
        return;  // stack is empty and unusued - no need to swap it out
    }

    if (*current_stack) {
        // try to swap out current stack for an empty stack
        for (int i = 0; i < DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
            ddtrace_coms_stack_t *stack_tmp = ddtrace_coms_global_state.stacks[i];
            if (stack_tmp && ddtrace_coms_is_stack_free(stack_tmp)) {
                // order is important due to ability to restore state on thread restart
                recycle_stack(stack_tmp);
                atomic_store(&ddtrace_coms_global_state.current_stack, stack_tmp);
                ddtrace_coms_global_state.stacks[i] = *current_stack;

                *current_stack = NULL;
                break;
            }
        }
    }

    // if we couldn't swap for a empty stack lets store it
    if (*current_stack) {
        for (int i = 0; i < DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
            ddtrace_coms_stack_t *stack_tmp = ddtrace_coms_global_state.stacks[i];
            if (!stack_tmp) {
                atomic_store(&ddtrace_coms_global_state.current_stack, NULL);
                ddtrace_coms_global_state.stacks[i] = *current_stack;
                *current_stack = NULL;

                break;
            }
        }
    }

    *current_stack = NULL;
}

BOOL_T ddtrace_coms_rotate_stack(BOOL_T attempt_allocate_new) {
    unsafe_store_or_swap_current_stack_for_empty_stack();

    ddtrace_coms_stack_t *current_stack = atomic_load(&ddtrace_coms_global_state.current_stack);

    if (current_stack && ddtrace_coms_is_stack_free(current_stack)) {
        return TRUE;
    }

    // old current stack was stored so set a new stack
    if (!current_stack) {
        if (attempt_allocate_new) {
            ddtrace_coms_stack_t **next_stack = &ddtrace_coms_global_state.tmp_stack;
            *next_stack = new_stack();
            atomic_store(&ddtrace_coms_global_state.current_stack, *next_stack);
            *next_stack = NULL;
            return TRUE;
        }
    }

    // we couldn't store old stack or allocate a new one so we cannot provide new empty stack
    return FALSE;
}

BOOL_T ddtrace_coms_buffer_data(uint32_t group_id, const char *data, size_t size) {
    if (!data) {
        return FALSE;
    }

    if (data && size == 0) {
        size = strlen(data);
    }

    if (size == 0) {
        return FALSE;
    }
    BOOL_T store_result = store_data(group_id, data, size);

    if (is_memory_pressure_high()) {
        ddtrace_coms_trigger_writer_flush();
    }

    if (store_result == ENOMEM) {
        ddtrace_coms_threadsafe_rotate_stack(TRUE);
        ddtrace_coms_trigger_writer_flush();
        store_result = store_data(group_id, data, size);
    }

    if (store_result == 0) {
        return TRUE;
    } else {
        return FALSE;
    }
}

group_id_t ddtrace_coms_next_group_id() { return atomic_fetch_add(&ddtrace_coms_global_state.next_group_id, 1); }

struct _grouped_stack_t {
    size_t position, total_bytes, total_groups;
    size_t bytes_to_write;

    char *dest_data;
    size_t dest_size;
};

static size_t write_array_header(char *buffer, size_t buffer_size, size_t position, uint32_t array_size) {
    size_t free_space = buffer_size - position;
    char *data = buffer + position;
    if (array_size < 16) {
        if (free_space >= 1) {
            mpack_store_u8(data, (uint8_t)(0x90 | array_size));
            return 1;
        }
    } else if (array_size < UINT16_MAX) {
        if (free_space >= 3) {
            mpack_store_u8(data, 0xdc);
            mpack_store_u16(data + 1, array_size);
            return 3;
        }
    } else {
        if (free_space >= 5) {
            mpack_store_u8(data, 0xdd);
            mpack_store_u32(data + 1, array_size);
            return 5;
        }
    }

    return 0;
}

static size_t write_to_buffer(char *buffer, size_t buffer_size, size_t position, struct _grouped_stack_t *read) {
    size_t write_size = read->bytes_to_write;
    if (write_size > 0) {
        if (write_size > (buffer_size - position)) {
            write_size = buffer_size - position;
        }
        if (write_size > (read->total_bytes - read->position)) {
            write_size = read->total_bytes - read->position;
        }

        memcpy(buffer + position, read->dest_data + read->position, write_size);

        read->position += write_size;
        read->bytes_to_write -= write_size;
    }

    return write_size;
}

static inline BOOL_T ensure_correct_dest_capacity(struct _grouped_stack_t *dest, size_t position, size_t write_size) {
    size_t requested_size = position + write_size;

    if (requested_size > dest->dest_size) {
        requested_size += requested_size / 10;  // addd 10% to reduce possible reallocations on next data chunk

        char *new_ptr = realloc(dest->dest_data, requested_size);
        if (new_ptr) {
            dest->dest_data = new_ptr;
            dest->dest_size = requested_size;
        } else {
            return FALSE;
        }
    }

    return TRUE;
}

void write_metadata(struct _grouped_stack_t *dest, size_t position, size_t elements_in_group, size_t bytes_in_group) {
    ensure_correct_dest_capacity(dest, position, sizeof(size_t) * 2);

    memcpy(dest->dest_data + position, &elements_in_group, sizeof(size_t));
    position += sizeof(size_t);
    memcpy(dest->dest_data + position, &bytes_in_group, sizeof(size_t));
}

void read_metadata(struct _grouped_stack_t *dest, size_t position, size_t *elements_in_group, size_t *bytes_in_group) {
    memcpy(elements_in_group, dest->dest_data + position, sizeof(size_t));

    position += sizeof(size_t);
    memcpy(bytes_in_group, dest->dest_data + position, sizeof(size_t));
}

size_t ddtrace_coms_read_callback(char *buffer, size_t size, size_t nitems, void *userdata) {
    if (!userdata) {
        return 0;
    }
    struct _grouped_stack_t *read = userdata;

    size_t written = 0;
    size_t buffer_size = size * nitems;

    if (read->total_groups > 0) {
        written += write_array_header(buffer, buffer_size, written, read->total_groups);
        read->total_groups = 0;
    }

    // write the remainder from previous iteration
    written += write_to_buffer(buffer, buffer_size, written, read);

    while (written < buffer_size) {
        // safe read size  check position + metadata
        if ((read->position + sizeof(size_t) * 2) > read->total_bytes) {
            break;
        }
        size_t num_elements = 0;

        read_metadata(read, read->position, &num_elements, &read->bytes_to_write);
        if (read->bytes_to_write == 0) {
            break;
        }
        written += write_array_header(buffer, buffer_size, written, num_elements);
        read->position += sizeof(size_t) * 2;

        written += write_to_buffer(buffer, buffer_size, written, read);
    }

    return written;
}

struct _entry_t {
    size_t size;
    group_id_t group_id;
    size_t next_entry_offset;
    char *data;
    char *raw_entry;
};

static inline struct _entry_t create_entry(ddtrace_coms_stack_t *stack, size_t position) {
    struct _entry_t rv = {.size = 0, .group_id = 0, .data = NULL, .next_entry_offset = 0};
    size_t bytes_written = atomic_load(&stack->bytes_written);

    if ((position + sizeof(size_t) + sizeof(group_id_t)) > bytes_written) {
        // wrong size available skip this entry
        return rv;
    }
    rv.raw_entry = stack->data + position;  // set pointer to beginning of the whole entry containing metadata

    memcpy(&rv.size, stack->data + position, sizeof(size_t));
    position += sizeof(size_t);

    memcpy(&rv.group_id, stack->data + position, sizeof(group_id_t));
    position += sizeof(group_id_t);

    if (rv.size > 0 && (rv.size + position) <= bytes_written) {
        // size is valid - save entry
        rv.data = stack->data + position;
        rv.next_entry_offset = sizeof(size_t) + sizeof(group_id_t) + rv.size;
    }
    return rv;
}

static inline void mark_entry_as_processed(struct _entry_t *entry) {
    group_id_t processed_special_id = GROUP_ID_PROCESSED;
    memcpy(entry->raw_entry + sizeof(size_t), &processed_special_id, sizeof(group_id_t));
}

static inline size_t append_entry(struct _entry_t *entry, struct _grouped_stack_t *dest, size_t position) {
    if (ensure_correct_dest_capacity(dest, position, entry->size)) {
        memcpy(dest->dest_data + position, entry->data, entry->size);
        return entry->size;
    } else {
        return 0;
    }
}

void ddtrace_msgpack_group_stack_by_id(ddtrace_coms_stack_t *stack, struct _grouped_stack_t *dest) {
    // perform an insertion sort by group_id
    uint32_t current_group_id = 0;
    struct _entry_t first_entry = create_entry(stack, 0);
    dest->total_bytes = 0;
    dest->total_groups = 0;

    if (!first_entry.data) {
        return;  // no entries
    }

    struct _entry_t next_group_entry = first_entry;

    current_group_id = first_entry.group_id;
    dest->total_groups++;
    size_t current_src_beginning = 0, next_src_beginning = 0, group_dest_beginning_position = 0;

    size_t bytes_written = atomic_load(&stack->bytes_written);

    while (current_src_beginning < bytes_written) {
        size_t current_src_position = current_src_beginning;
        size_t group_dest_position = group_dest_beginning_position;

        // group metadata
        size_t elements_in_group = 0;
        size_t bytes_in_group = 0;
        group_dest_position += sizeof(size_t) * 2;  // leave place for group meta data
        size_t i = 0;
        while (current_src_position < bytes_written) {
            struct _entry_t entry = create_entry(stack, current_src_position);
            i++;
            if (entry.size == 0) {
                break;
            }

            if (entry.group_id == current_group_id) {
                size_t copied = append_entry(&entry, dest, group_dest_position);
                if (copied > 0) {
                    mark_entry_as_processed(&entry);
                    elements_in_group++;
                    group_dest_position += copied;
                    bytes_in_group += copied;
                }
            } else if (next_group_entry.group_id == current_group_id && entry.group_id != GROUP_ID_PROCESSED) {
                dest->total_groups++;  // add unique group count
                next_group_entry = entry;
                next_src_beginning = current_src_position;
            }
            current_src_position += entry.next_entry_offset;
        }

        write_metadata(dest, group_dest_beginning_position, elements_in_group, bytes_in_group);
        group_dest_beginning_position = group_dest_position;

        // no new groups - exit loop
        if (next_group_entry.group_id == current_group_id) {
            break;
        }

        current_group_id = next_group_entry.group_id;
        current_src_beginning = next_src_beginning;
    }
    dest->total_bytes = group_dest_beginning_position;  // save total bytes count after conversion

    return;
}

void *ddtrace_init_read_userdata(ddtrace_coms_stack_t *stack) {
    size_t total_bytes = atomic_load(&stack->bytes_written);

    struct _grouped_stack_t *readstack_ptr = malloc(sizeof(struct _grouped_stack_t));
    struct _grouped_stack_t readstack = {.position = 0, .total_bytes = total_bytes};

    readstack.dest_size = atomic_load(&stack->bytes_written) + 2000;
    readstack.dest_data = malloc(readstack.dest_size);

    ddtrace_msgpack_group_stack_by_id(stack, &readstack);
    *readstack_ptr = readstack;

    return readstack_ptr;
}

void ddtrace_deinit_read_userdata(void *userdata) {
    struct _grouped_stack_t *data = userdata;
    if (data->dest_data) {
        free(data->dest_data);
    }
    free(userdata);
}

ddtrace_coms_stack_t *ddtrace_coms_attempt_acquire_stack() {
    ddtrace_coms_stack_t *stack = NULL;

    for (int i = 0; i < DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        ddtrace_coms_stack_t *stack_tmp = ddtrace_coms_global_state.stacks[i];
        if (stack_tmp && atomic_load(&stack_tmp->refcount) == 0 && atomic_load(&stack_tmp->bytes_written) > 0) {
            stack = stack_tmp;
            ddtrace_coms_global_state.stacks[i] = NULL;
            break;
        }
    }

    return stack;
}
