#include <SAPI.h>
#include <curl/curl.h>
#include <errno.h>
#include <pthread.h>
#include <signal.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>
#include <sys/time.h>
#include <time.h>
#include <unistd.h>

// For reasons it doesn't find asprintf() if this isn't included later...
#include "coms.h"

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#if HAVE_LINUX_SECUREBITS_H
#include <linux/securebits.h>
#include <sys/prctl.h>
#endif
#if HAVE_LINUX_CAPABILITY_H
#include <linux/capability.h>
#include <sys/syscall.h>
#endif

#include "compatibility.h"
#include "configuration.h"
#include "ddshared.h"
#include "ext/version.h"
#include "logging.h"
#include "mpack/mpack.h"

extern inline bool ddtrace_coms_is_stack_unused(ddtrace_coms_stack_t *stack);
extern inline bool ddtrace_coms_is_stack_free(ddtrace_coms_stack_t *stack);

typedef uint32_t group_id_t;

#define GROUP_ID_PROCESSED (1UL << 31UL)

ddtrace_coms_state_t ddtrace_coms_globals = {.stacks = NULL};

static bool _dd_is_memory_pressure_high(void) {
    ddtrace_coms_stack_t *stack = atomic_load(&ddtrace_coms_globals.current_stack);
    if (stack) {
        int64_t used = (((double)atomic_load(&stack->position) / (double)stack->size) * 100);
        return used > get_global_DD_TRACE_BETA_HIGH_MEMORY_PRESSURE_PERCENT();
    } else {
        return false;
    }
}

static uint32_t _dd_store_data(group_id_t group_id, const char *src, size_t size) {
    ddtrace_coms_stack_t *stack = atomic_load(&ddtrace_coms_globals.current_stack);
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

/* Allocates a new stack of the minimum possible size. Only if `min_size` (which is the size required by the user to
 * fit in a given payload) is larger than the currently active stack size, then new sizes are attempted attemptiong
 * to double at each iteration, up to `DDTRACE_COMS_STACK_MAX_SIZE`.
 * The rationale behind this is that once we know that at least one single trace can be larger than X bytes, then
 * all the subsequent stacks are allocated at least as large as that size.
 */
static ddtrace_coms_stack_t *_dd_new_stack(size_t min_size) {
    size_t initial_size = atomic_load(&ddtrace_coms_globals.stack_size);
    size_t size = initial_size;
    while (min_size > size && size <= DDTRACE_COMS_STACK_HALF_MAX_SIZE) {
        size *= 2;
    }
    if (size != initial_size) {
        // If we fail to update the global twice in a row, we can just rely on dynamic size allocation in the future
        int i = 2;
        while (!atomic_compare_exchange_weak(&ddtrace_coms_globals.stack_size, &initial_size, size) && i--) {
            if (initial_size > size) {
                size = initial_size;
                break;
            }
        };
    }
    ddtrace_coms_stack_t *stack = calloc(1, sizeof(ddtrace_coms_stack_t));
    stack->size = size;
    stack->data = calloc(1, size);

    return stack;
}

static void _dd_coms_free_stack(ddtrace_coms_stack_t *stack) {
    free(stack->data);
    free(stack);
}

static void _dd_recycle_stack(ddtrace_coms_stack_t *stack) {
    char *data = stack->data;
    size_t size = stack->size;

    memset(stack, 0, sizeof(ddtrace_coms_stack_t));
    memset(data, 0, size);

    stack->data = data;
    stack->size = size;
}

static void (*_dd_ptr_at_exit_callback)(void) = 0;

static void _dd_at_exit_callback() { ddtrace_coms_flush_shutdown_writer_synchronous(); }

static void _dd_at_exit_hook() {
    if (_dd_ptr_at_exit_callback) {
        _dd_ptr_at_exit_callback();
    }
}

bool ddtrace_coms_minit(void) {
    atomic_store(&ddtrace_coms_globals.stack_size, DDTRACE_COMS_STACK_INITIAL_SIZE);
    ddtrace_coms_stack_t *stack = _dd_new_stack(DDTRACE_COMS_STACK_INITIAL_SIZE);
    if (!ddtrace_coms_globals.stacks) {
        ddtrace_coms_globals.stacks = calloc(DDTRACE_COMS_STACKS_BACKLOG_SIZE, sizeof(ddtrace_coms_stack_t *));
    }

    atomic_store(&ddtrace_coms_globals.next_group_id, 1);
    atomic_store(&ddtrace_coms_globals.current_stack, stack);

    _dd_ptr_at_exit_callback = _dd_at_exit_callback;
    atexit(_dd_at_exit_hook);

    return true;
}

void ddtrace_coms_mshutdown(void) { _dd_ptr_at_exit_callback = NULL; }

static void _dd_coms_stack_shutdown(void) {
    ddtrace_coms_stack_t *current_stack = atomic_load(&ddtrace_coms_globals.current_stack);
    if (current_stack) {
        if (current_stack->data) {
            free(current_stack->data);
        }
        free(current_stack);
    }
    if (ddtrace_coms_globals.stacks) {
        free(ddtrace_coms_globals.stacks);
        ddtrace_coms_globals.stacks = NULL;
    }
}

#if 0
static void printf_stack_info(ddtrace_coms_stack_t *stack) {
    printf("stack (%p) refcount: (%d) bytes_written: (%lu)\n", stack, atomic_load(&stack->refcount),
           atomic_load(&stack->bytes_written));
}
#endif

static void _dd_unsafe_store_or_discard_stack(ddtrace_coms_stack_t *stack) {
    for (int i = 0; i < DDTRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        ddtrace_coms_stack_t *stack_tmp = ddtrace_coms_globals.stacks[i];
        if (stack_tmp == stack) {
            return;
        }

        if (stack_tmp == NULL) {
            ddtrace_coms_globals.stacks[i] = stack;
            return;
        }
    }

    _dd_coms_free_stack(stack);
}

static void _dd_unsafe_cleanup_dirty_stack_area(void) {
    ddtrace_coms_stack_t *current_stack = atomic_load(&ddtrace_coms_globals.current_stack);
    if (!ddtrace_coms_globals.tmp_stack) {
        return;
    }

    if (ddtrace_coms_globals.tmp_stack != current_stack) {
        ddtrace_coms_stack_t *stack = ddtrace_coms_globals.tmp_stack;

        atomic_store(&stack->refcount, 0);
        _dd_unsafe_store_or_discard_stack(stack);
    }
    ddtrace_coms_globals.tmp_stack = NULL;
}

/* Stores the global current stack (if any) into the global `stacks` array. The writer will pick from this array when
 * sending payloads to the backend. This function is invoked when a global current stack is "filled enough" to be sent
 * to the backend.
 * This function has a side effect: it tries to save memory by using as the "next" global current stack one of the
 * stacks in the global `stack` array mentioned above. This is possible if a payload contained in one of the elements of
 * the array has already been sent to the backend and, as a consequence, that element is ready for reuse. Keep in mind
 * that in order to be ready for reuse it has to be at least of size `min_size`.
 * It is possible that the global current stack is set to `NULL` by this function. In this case it means that there were
 * no available existing stacks that could store `min_size` bytes and it is the invoker's own responsibility to allocate
 * a new stack of the desired size and assign it to the global current stack.
 */
static void _dd_unsafe_store_or_swap_current_stack_for_empty_stack(size_t min_size) {
    _dd_unsafe_cleanup_dirty_stack_area();

    // store the temp variable if we ever need to recover it
    ddtrace_coms_stack_t **current_stack = &ddtrace_coms_globals.tmp_stack;

    *current_stack = atomic_load(&ddtrace_coms_globals.current_stack);

    if (*current_stack && (*current_stack)->size >= min_size && ddtrace_coms_is_stack_free(*current_stack)) {
        *current_stack = NULL;
        return;  // stack is empty and unusued - no need to swap it out
    }

    if (*current_stack) {
        // try to swap out current stack for an empty stack
        for (int i = 0; i < DDTRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
            ddtrace_coms_stack_t *stack_tmp = ddtrace_coms_globals.stacks[i];
            if (stack_tmp && stack_tmp->size >= min_size && ddtrace_coms_is_stack_free(stack_tmp)) {
                // order is important due to ability to restore state on thread restart
                _dd_recycle_stack(stack_tmp);
                atomic_store(&ddtrace_coms_globals.current_stack, stack_tmp);
                ddtrace_coms_globals.stacks[i] = *current_stack;

                *current_stack = NULL;
                break;
            }
        }
    }

    // if we couldn't swap for a empty stack lets store it
    if (*current_stack) {
        for (int i = 0; i < DDTRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
            ddtrace_coms_stack_t *stack_tmp = ddtrace_coms_globals.stacks[i];
            if (!stack_tmp) {
                atomic_store(&ddtrace_coms_globals.current_stack, NULL);
                ddtrace_coms_globals.stacks[i] = *current_stack;
                *current_stack = NULL;

                break;
            }
        }
    }

    *current_stack = NULL;
}

static bool _dd_coms_unsafe_rotate_stack(bool attempt_allocate_new, size_t min_size) {
    _dd_unsafe_store_or_swap_current_stack_for_empty_stack(min_size);

    ddtrace_coms_stack_t *current_stack = atomic_load(&ddtrace_coms_globals.current_stack);

    if (current_stack && current_stack->size >= min_size && ddtrace_coms_is_stack_free(current_stack)) {
        return true;
    }

    /* In this case it wasn't possible to reuse an existing stack, for one of two reasons:
     *   1. All the N currently allocated stacks (with N <= `DDTRACE_COMS_STACKS_BACKLOG_SIZE`) are filled and waiting
     *      to be sent by the writer; or
     *   2. None of the available stacks in the global `stacks` array that could be reused has size >= `min_size`.
     */
    if (!current_stack) {
        if (attempt_allocate_new) {
            ddtrace_coms_stack_t **next_stack = &ddtrace_coms_globals.tmp_stack;
            *next_stack = _dd_new_stack(min_size);
            atomic_store(&ddtrace_coms_globals.current_stack, *next_stack);
            *next_stack = NULL;
            return true;
        }
    }

    // we couldn't store old stack or allocate a new one so we cannot provide new empty stack
    return false;
}

struct _writer_thread_variables_t {
    pthread_t self;
    pthread_mutex_t interval_flush_mutex, finished_flush_mutex, stack_rotation_mutex;
    pthread_mutex_t writer_shutdown_signal_mutex;
    pthread_cond_t writer_shutdown_signal_condition;
    pthread_cond_t interval_flush_condition, finished_flush_condition;
};

struct _writer_loop_data_t {
    CURL *curl;
    struct curl_slist *headers;
    ddtrace_coms_stack_t *tmp_stack;

    struct _writer_thread_variables_t *thread;

    bool set_secbit;

    _Atomic(bool) running, starting_up;
    _Atomic(pid_t) current_pid;
    _Atomic(bool) shutdown_when_idle, suspended, sending, allocate_new_stacks;
    _Atomic(uint32_t) flush_interval, request_counter, flush_processed_stacks_total, writer_cycle,
        requests_since_last_flush;
};

static struct _writer_loop_data_t global_writer = {.thread = NULL,
                                                   .set_secbit = 0,
                                                   .running = ATOMIC_VAR_INIT(0),
                                                   .current_pid = ATOMIC_VAR_INIT(0),
                                                   .shutdown_when_idle = ATOMIC_VAR_INIT(0),
                                                   .suspended = ATOMIC_VAR_INIT(0),
                                                   .allocate_new_stacks = ATOMIC_VAR_INIT(0),
                                                   .sending = ATOMIC_VAR_INIT(0)};

static struct _writer_loop_data_t *_dd_get_writer() { return &global_writer; }

static bool ddtrace_coms_threadsafe_rotate_stack(bool attempt_allocate_new, size_t min_size) {
    struct _writer_loop_data_t *writer = _dd_get_writer();
    bool rv = false;
    if (writer->thread) {
        pthread_mutex_lock(&writer->thread->stack_rotation_mutex);
        rv = _dd_coms_unsafe_rotate_stack(attempt_allocate_new, min_size);
        pthread_mutex_unlock(&writer->thread->stack_rotation_mutex);
    }
    return rv;
}

bool ddtrace_coms_buffer_data(uint32_t group_id, const char *data, size_t size) {
    if (!data || size > DDTRACE_COMS_STACK_MAX_SIZE) {
        return false;
    }

    if (size == 0) {
        size = strlen(data);
        if (size == 0) {
            return false;
        }
    }

    uint32_t store_result = _dd_store_data(group_id, data, size);

    if (_dd_is_memory_pressure_high()) {
        ddtrace_coms_trigger_writer_flush();
    }

    if (store_result == ENOMEM) {
        size_t padding = 2;
        ddtrace_coms_threadsafe_rotate_stack(true, size + padding);
        ddtrace_coms_trigger_writer_flush();
        store_result = _dd_store_data(group_id, data, size);
    }

    return store_result == 0;
}

group_id_t ddtrace_coms_next_group_id(void) { return atomic_fetch_add(&ddtrace_coms_globals.next_group_id, 1); }

struct _grouped_stack_t {
    size_t position, total_bytes, total_groups;
    size_t bytes_to_write;

    char *dest_data;
    size_t dest_size;
};

static size_t _dd_write_array_header(char *buffer, size_t buffer_size, size_t position, uint32_t array_size) {
    size_t free_space = buffer_size - position;
    char *data = buffer + position;
    if (array_size < 16) {
        if (free_space >= 1) {
            mpack_store_u8(data, (uint8_t)(0x90u | array_size));
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

static size_t _dd_write_to_buffer(char *buffer, size_t buffer_size, size_t position, struct _grouped_stack_t *read) {
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

static bool _dd_ensure_correct_dest_capacity(struct _grouped_stack_t *dest, size_t position, size_t write_size) {
    size_t requested_size = position + write_size;

    if (requested_size > dest->dest_size) {
        requested_size += requested_size / 10;  // addd 10% to reduce possible reallocations on next data chunk

        char *new_ptr = realloc(dest->dest_data, requested_size);
        if (new_ptr) {
            dest->dest_data = new_ptr;
            dest->dest_size = requested_size;
        } else {
            return false;
        }
    }

    return true;
}

static void _dd_write_metadata(struct _grouped_stack_t *dest, size_t position, size_t elements_in_group,
                               size_t bytes_in_group) {
    _dd_ensure_correct_dest_capacity(dest, position, sizeof(size_t) * 2);

    memcpy(dest->dest_data + position, &elements_in_group, sizeof(size_t));
    position += sizeof(size_t);
    memcpy(dest->dest_data + position, &bytes_in_group, sizeof(size_t));
}

static void _dd_read_metadata(struct _grouped_stack_t *dest, size_t position, size_t *elements_in_group,
                              size_t *bytes_in_group) {
    memcpy(elements_in_group, dest->dest_data + position, sizeof(size_t));

    position += sizeof(size_t);
    memcpy(bytes_in_group, dest->dest_data + position, sizeof(size_t));
}

static size_t _dd_coms_read_callback(char *buffer, size_t size, size_t nitems, void *userdata) {
    if (!userdata) {
        return 0;
    }
    struct _grouped_stack_t *read = userdata;

    size_t written = 0;
    size_t buffer_size = size * nitems;

    if (read->total_groups > 0) {
        written += _dd_write_array_header(buffer, buffer_size, written, read->total_groups);
        read->total_groups = 0;
    }

    // write the remainder from previous iteration
    written += _dd_write_to_buffer(buffer, buffer_size, written, read);

    while (written < buffer_size) {
        // safe read size  check position + metadata
        if ((read->position + sizeof(size_t) * 2) > read->total_bytes) {
            break;
        }
        size_t num_elements = 0;

        _dd_read_metadata(read, read->position, &num_elements, &read->bytes_to_write);
        if (read->bytes_to_write == 0) {
            break;
        }
        // written += _dd_write_array_header(buffer, buffer_size, written, num_elements);
        read->position += sizeof(size_t) * 2;

        written += _dd_write_to_buffer(buffer, buffer_size, written, read);
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

static struct _entry_t _dd_create_entry(ddtrace_coms_stack_t *stack, size_t position) {
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

static void _dd_mark_entry_as_processed(struct _entry_t *entry) {
    group_id_t processed_special_id = GROUP_ID_PROCESSED;
    memcpy(entry->raw_entry + sizeof(size_t), &processed_special_id, sizeof(group_id_t));
}

static size_t _dd_append_entry(struct _entry_t *entry, struct _grouped_stack_t *dest, size_t position) {
    if (_dd_ensure_correct_dest_capacity(dest, position, entry->size)) {
        memcpy(dest->dest_data + position, entry->data, entry->size);
        return entry->size;
    } else {
        return 0;
    }
}

static void _dd_msgpack_group_stack_by_id(ddtrace_coms_stack_t *stack, struct _grouped_stack_t *dest) {
    // perform an insertion sort by group_id
    uint32_t current_group_id = 0;
    struct _entry_t first_entry = _dd_create_entry(stack, 0);
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
            struct _entry_t entry = _dd_create_entry(stack, current_src_position);
            i++;
            if (entry.size == 0) {
                break;
            }

            if (entry.group_id == current_group_id) {
                size_t copied = _dd_append_entry(&entry, dest, group_dest_position);
                if (copied > 0) {
                    _dd_mark_entry_as_processed(&entry);
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

        _dd_write_metadata(dest, group_dest_beginning_position, elements_in_group, bytes_in_group);
        group_dest_beginning_position = group_dest_position;

        // no new groups - exit loop
        if (next_group_entry.group_id == current_group_id) {
            break;
        }

        current_group_id = next_group_entry.group_id;
        current_src_beginning = next_src_beginning;
    }
    dest->total_bytes = group_dest_beginning_position;  // save total bytes count after conversion
}

static void *_dd_init_read_userdata(ddtrace_coms_stack_t *stack) {
    size_t total_bytes = atomic_load(&stack->bytes_written);

    struct _grouped_stack_t *readstack = calloc(1, sizeof(struct _grouped_stack_t));
    readstack->total_bytes = total_bytes;
    readstack->dest_size = atomic_load(&stack->bytes_written) + 2000;
    readstack->dest_data = malloc(readstack->dest_size);

    _dd_msgpack_group_stack_by_id(stack, readstack);

    return readstack;
}

static void _dd_deinit_read_userdata(void *userdata) {
    struct _grouped_stack_t *data = userdata;
    if (data->dest_data) {
        free(data->dest_data);
    }
    free(userdata);
}

static ddtrace_coms_stack_t *_dd_coms_attempt_acquire_stack(void) {
    ddtrace_coms_stack_t *stack = NULL;

    for (int i = 0; i < DDTRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        ddtrace_coms_stack_t *stack_tmp = ddtrace_coms_globals.stacks[i];
        if (stack_tmp && atomic_load(&stack_tmp->refcount) == 0 && atomic_load(&stack_tmp->bytes_written) > 0) {
            stack = stack_tmp;
            ddtrace_coms_globals.stacks[i] = NULL;
            break;
        }
    }

    return stack;
}

#define TRACE_PATH_STR "/v0.4/traces"
#define HOST_FORMAT_STR "http://%s:%u"

static struct curl_slist *dd_agent_curl_headers = NULL;

static void dd_append_header(struct curl_slist **list, const char *key, const char *val) {
    /* The longest Agent header should be:
     * Datadog-Container-Id: <64-char-hash>
     * So 256 should give us plenty of wiggle room.
     */
    char header[256];
    size_t len = snprintf(header, sizeof header, "%s: %s", key, val);
    if (len > 0 && len < sizeof header) {
        *list = curl_slist_append(*list, header);
    }
}

static struct curl_slist *dd_agent_headers_alloc(void) {
    struct curl_slist *list = NULL;

    dd_append_header(&list, "Datadog-Meta-Lang", "php");
    dd_append_header(&list, "Datadog-Meta-Lang-Interpreter", sapi_module.name);
    dd_append_header(&list, "Datadog-Meta-Lang-Version", PHP_VERSION);
    dd_append_header(&list, "Datadog-Meta-Tracer-Version", PHP_DDTRACE_VERSION);

    char *id = ddshared_container_id();
    if (id != NULL && id[0] != '\0') {
        dd_append_header(&list, "Datadog-Container-Id", id);
    }

    /* Curl will add Expect: 100-continue if it is a POST over a certain size. The trouble is that CURL will
     * wait for *1 second* for 100 Continue response before sending the rest of the data. This wait is
     * configurable, but requires a newer curl than we have on CentOS 6. So instead we send an empty Expect.
     */
    dd_append_header(&list, "Expect", "");

    return list;
}

static void dd_agent_headers_free(struct curl_slist *list) {
    if (list != NULL) {
        curl_slist_free_all(list);
    }
}

void ddtrace_coms_curl_shutdown(void) { dd_agent_headers_free(dd_agent_curl_headers); }

static long _dd_max_long(long a, long b) { return a >= b ? a : b; }

void ddtrace_curl_set_timeout(CURL *curl) {
    long timeout = _dd_max_long(get_global_DD_TRACE_BGS_TIMEOUT(), get_global_DD_TRACE_AGENT_TIMEOUT());
    curl_easy_setopt(curl, CURLOPT_TIMEOUT_MS, timeout);
}

void ddtrace_curl_set_connect_timeout(CURL *curl) {
    long timeout = _dd_max_long(get_global_DD_TRACE_BGS_CONNECT_TIMEOUT(), get_global_DD_TRACE_AGENT_CONNECT_TIMEOUT());
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT_MS, timeout);
}

char *ddtrace_agent_url(void) {
    zend_string *url = get_global_DD_TRACE_AGENT_URL();
    if (ZSTR_LEN(url) > 0) {
        return zend_strndup(ZSTR_VAL(url), ZSTR_LEN(url));
    }

    zend_string *hostname = get_global_DD_AGENT_HOST();
    if (ZSTR_LEN(hostname) > 0) {
        int64_t port = get_global_DD_TRACE_AGENT_PORT();
        if (port <= 0 || port > 65535) {
            port = 8126;
        }
        char *formatted_url;
        asprintf(&formatted_url, HOST_FORMAT_STR, ZSTR_VAL(hostname), (uint32_t)port);
        return formatted_url;
    }

    return zend_strndup(ZEND_STRL("http://localhost:8126"));
}

void ddtrace_curl_set_hostname(CURL *curl) {
    char *url = ddtrace_agent_url();
    if (url && url[0]) {
        size_t agent_url_len = strlen(url) + sizeof(TRACE_PATH_STR);
        char *agent_url = malloc(agent_url_len);
        sprintf(agent_url, "%s%s", url, TRACE_PATH_STR);
        curl_easy_setopt(curl, CURLOPT_URL, agent_url);
        free(agent_url);
    }
    free(url);
}

static struct timespec _dd_deadline_in_ms(uint32_t ms) {
    struct timespec deadline;
    struct timeval now;

    gettimeofday(&now, NULL);
    uint32_t sec = ms / 1000UL;
    uint32_t msec = ms % 1000UL;
    deadline.tv_sec = now.tv_sec + sec;
    deadline.tv_nsec = ((now.tv_usec + 1000UL * msec) * 1000UL);

    // carry over full seconds from nsec
    deadline.tv_sec += deadline.tv_nsec / (1000 * 1000 * 1000);
    deadline.tv_nsec %= (1000 * 1000 * 1000);

    return deadline;
}

static size_t _dd_dummy_write_callback(char *ptr, size_t size, size_t nmemb, void *userdata) {
    UNUSED(userdata);
    size_t data_length = size * nmemb;
    ddtrace_bgs_logf("%s", ptr);
    return data_length;
}

#define DD_TRACE_COUNT_HEADER "X-Datadog-Trace-Count: "

static void _dd_curl_set_headers(struct _writer_loop_data_t *writer, size_t trace_count) {
    struct curl_slist *headers = NULL;
    for (struct curl_slist *current = dd_agent_curl_headers; current; current = current->next) {
        headers = curl_slist_append(headers, current->data);
    }
    headers = curl_slist_append(headers, "Transfer-Encoding: chunked");
    headers = curl_slist_append(headers, "Content-Type: application/msgpack");

    char buffer[64];
    int bytes_written = snprintf(buffer, sizeof buffer, DD_TRACE_COUNT_HEADER "%zu", trace_count);
    if (bytes_written > ((int)sizeof(DD_TRACE_COUNT_HEADER)) - 1 && bytes_written < ((int)sizeof buffer)) {
        headers = curl_slist_append(headers, buffer);
    }

    if (writer->headers) {
        curl_slist_free_all(writer->headers);
    }

    curl_easy_setopt(writer->curl, CURLOPT_HTTPHEADER, headers);
    writer->headers = headers;
}

static void _dd_curl_send_stack(struct _writer_loop_data_t *writer, ddtrace_coms_stack_t *stack) {
    if (!writer->curl) {
        ddtrace_bgs_logf("[bgs] no curl session - dropping the current stack.\n", NULL);
    }

    if (writer->curl) {
        CURLcode res;

        void *read_data = _dd_init_read_userdata(stack);
        struct _grouped_stack_t *kData = read_data;
        _dd_curl_set_headers(writer, kData->total_groups);
        curl_easy_setopt(writer->curl, CURLOPT_READDATA, read_data);
        ddtrace_curl_set_hostname(writer->curl);
        ddtrace_curl_set_timeout(writer->curl);
        ddtrace_curl_set_connect_timeout(writer->curl);

        curl_easy_setopt(writer->curl, CURLOPT_UPLOAD, 1);
        curl_easy_setopt(writer->curl, CURLOPT_VERBOSE, get_global_DD_TRACE_AGENT_DEBUG_VERBOSE_CURL());

        res = curl_easy_perform(writer->curl);

        if (res != CURLE_OK) {
            ddtrace_bgs_logf("[bgs] curl_easy_perform() failed: %s\n", curl_easy_strerror(res));
        } else if (get_global_DD_TRACE_DEBUG_CURL_OUTPUT()) {
            double uploaded;
            curl_easy_getinfo(writer->curl, CURLINFO_SIZE_UPLOAD, &uploaded);
            ddtrace_bgs_logf("[bgs] uploaded %.0f bytes\n", uploaded);
        }

        _dd_deinit_read_userdata(read_data);
        curl_slist_free_all(writer->headers);
        writer->headers = NULL;
    }
}
static void _dd_signal_writer_started(struct _writer_loop_data_t *writer) {
    if (writer->thread) {
        // at the moment no actual signal is sent but we will set a threadsafe state variable
        // ordering is important to correctly state that writer is either running or stil is starting up
        atomic_store(&writer->running, true);
        atomic_store(&writer->starting_up, false);
    }
}

static void _dd_signal_writer_finished(struct _writer_loop_data_t *writer) {
    if (writer->thread) {
        pthread_mutex_lock(&writer->thread->writer_shutdown_signal_mutex);
        atomic_store(&writer->running, false);

        pthread_cond_signal(&writer->thread->writer_shutdown_signal_condition);
        pthread_mutex_unlock(&writer->thread->writer_shutdown_signal_mutex);
    }
}

static void _dd_signal_data_processed(struct _writer_loop_data_t *writer) {
    if (writer->thread) {
        pthread_mutex_lock(&writer->thread->finished_flush_mutex);
        pthread_cond_signal(&writer->thread->finished_flush_condition);
        pthread_mutex_unlock(&writer->thread->finished_flush_mutex);
    }
}

#ifdef __CYGWIN__
#define TIMEOUT_SIG SIGALRM
#else
#define TIMEOUT_SIG SIGPROF
#endif

static void _dd_writer_loop_cleanup(void *ctx) { _dd_signal_writer_finished((struct _writer_loop_data_t *)ctx); }

static void *_dd_writer_loop(void *_) {
    UNUSED(_);
    /* This thread must not handle signals intended for the PHP threads.
     * See Zend/zend_signal.c for which signals it registers.
     */
    sigset_t sigset;
    sigemptyset(&sigset);
    sigaddset(&sigset, TIMEOUT_SIG);
    sigaddset(&sigset, SIGHUP);
    sigaddset(&sigset, SIGINT);
    sigaddset(&sigset, SIGQUIT);
    sigaddset(&sigset, SIGTERM);
    sigaddset(&sigset, SIGUSR1);
    sigaddset(&sigset, SIGUSR2);
    pthread_sigmask(SIG_BLOCK, &sigset, NULL);

    struct _writer_loop_data_t *writer = _dd_get_writer();

#if HAVE_LINUX_SECUREBITS_H
    if (writer->set_secbit) {
        // prevent setuid from messing with our effective capabilities
        // this is necessary to handle scenarios where setuid is only called after starting our thread
        prctl(PR_SET_SECUREBITS, SECBIT_NO_SETUID_FIXUP);
    }
#endif

#if HAVE_LINUX_CAPABILITY_H
    // restore the permitted capabilities to the effective set
    // some applications may call setuid(2) with prctl(PR_SET_KEEPCAPS) active, but this will still clear all the
    // effective capabilities To ensure proper functionality under these circumstances, we need to undo the effective
    // capability clearing. This is safe.
    struct __user_cap_header_struct caphdrp = {.version = _LINUX_CAPABILITY_VERSION_3};
    struct __user_cap_data_struct capdatap[_LINUX_CAPABILITY_U32S_3];
    if (syscall(SYS_capget, &caphdrp, &capdatap) == 0) {
        for (int i = 0; i < _LINUX_CAPABILITY_U32S_3; ++i) {
            capdatap[i].effective = capdatap[i].permitted;
        }
        syscall(SYS_capset, &caphdrp, &capdatap);
    }
#endif

    pthread_cleanup_push(_dd_writer_loop_cleanup, writer);

    bool running = true;
    _dd_signal_writer_started(writer);
    do {
        atomic_fetch_add(&writer->writer_cycle, 1);
        uint32_t interval = atomic_load(&writer->flush_interval);
        // fprintf(stderr, "interval %lu\n", interval);
        if (interval > 0) {
            struct timespec wait_deadline = _dd_deadline_in_ms(interval);
            if (writer->thread) {
                pthread_mutex_lock(&writer->thread->interval_flush_mutex);
                pthread_cond_timedwait(&writer->thread->interval_flush_condition, &writer->thread->interval_flush_mutex,
                                       &wait_deadline);
                pthread_mutex_unlock(&writer->thread->interval_flush_mutex);
            }
        }

        if (atomic_load(&writer->suspended)) {
            continue;
        }

        atomic_store(&writer->requests_since_last_flush, 0);

        ddtrace_coms_stack_t **stack = &writer->tmp_stack;
        ddtrace_coms_threadsafe_rotate_stack(atomic_load(&writer->allocate_new_stacks),
                                             DDTRACE_COMS_STACK_INITIAL_SIZE);

        uint32_t processed_stacks = 0;
        if (!*stack) {
            *stack = _dd_coms_attempt_acquire_stack();
        }

        // initializing a curl client only for this iteration
        writer->curl = curl_easy_init();
        curl_easy_setopt(writer->curl, CURLOPT_READFUNCTION, _dd_coms_read_callback);
        curl_easy_setopt(writer->curl, CURLOPT_WRITEFUNCTION, _dd_dummy_write_callback);

        while (*stack) {
            processed_stacks++;
            if (atomic_load(&writer->sending)) {
                _dd_curl_send_stack(writer, *stack);
            }

            ddtrace_coms_stack_t *to_free = *stack;
            // successfully sent stack is no longer needed
            // ensure no one will refernce freed stack when thread restarts after fork
            *stack = NULL;
            _dd_coms_free_stack(to_free);

            *stack = _dd_coms_attempt_acquire_stack();
        }

        curl_easy_cleanup(writer->curl);

        if (processed_stacks > 0) {
            atomic_fetch_add(&writer->flush_processed_stacks_total, processed_stacks);
        } else if (atomic_load(&writer->shutdown_when_idle)) {
            running = false;
        }

        _dd_signal_data_processed(writer);
    } while (running);

    curl_slist_free_all(writer->headers);
    writer->headers = NULL;

    _dd_coms_stack_shutdown();

    pthread_cleanup_pop(1);

    return NULL;
}

bool ddtrace_coms_set_writer_send_on_flush(bool send) {
    struct _writer_loop_data_t *writer = _dd_get_writer();
    bool previous_value = atomic_load(&writer->sending);
    atomic_store(&writer->sending, send);

    return previous_value;
}

static void _dd_writer_set_shutdown_state(struct _writer_loop_data_t *writer) {
    // spin the writer without waiting to speedup processing time
    atomic_store(&writer->flush_interval, 0);
    // stop allocating new stacks on flush
    atomic_store(&writer->allocate_new_stacks, false);
    // make the writer exit once it finishes the processing
    atomic_store(&writer->shutdown_when_idle, true);
}

static void _dd_writer_set_operational_state(struct _writer_loop_data_t *writer) {
    atomic_store(&writer->sending, true);
    atomic_store(&writer->flush_interval, get_global_DD_TRACE_AGENT_FLUSH_INTERVAL());
    atomic_store(&writer->allocate_new_stacks, true);
    atomic_store(&writer->shutdown_when_idle, false);
}

static struct _writer_thread_variables_t *_dd_create_thread_variables() {
    struct _writer_thread_variables_t *thread = calloc(1, sizeof(struct _writer_thread_variables_t));
    pthread_mutex_init(&thread->interval_flush_mutex, NULL);
    pthread_mutex_init(&thread->finished_flush_mutex, NULL);
    pthread_mutex_init(&thread->stack_rotation_mutex, NULL);

    pthread_mutex_init(&thread->writer_shutdown_signal_mutex, NULL);
    pthread_cond_init(&thread->writer_shutdown_signal_condition, NULL);

    pthread_cond_init(&thread->interval_flush_condition, NULL);
    pthread_cond_init(&thread->finished_flush_condition, NULL);

    return thread;
}

bool ddtrace_coms_init_and_start_writer(void) {
    struct _writer_loop_data_t *writer = _dd_get_writer();
    _dd_writer_set_operational_state(writer);
    atomic_store(&writer->current_pid, getpid());

    dd_agent_curl_headers = dd_agent_headers_alloc();

    if (writer->thread) {
        return false;
    }
    struct _writer_thread_variables_t *thread = _dd_create_thread_variables();
    writer->thread = thread;
    writer->set_secbit = get_global_DD_TRACE_RETAIN_THREAD_CAPABILITIES();
    atomic_store(&writer->starting_up, true);
    if (pthread_create(&thread->self, NULL, &_dd_writer_loop, NULL) == 0) {
        return true;
    } else {
        return false;
    }
}

static bool _dd_has_pid_changed(void) {
    struct _writer_loop_data_t *writer = _dd_get_writer();
    pid_t current_pid = getpid();
    pid_t previous_pid = atomic_load(&writer->current_pid);
    return current_pid != previous_pid;
}

void ddtrace_coms_kill_background_sender(void) {
    struct _writer_loop_data_t *writer = _dd_get_writer();
    if (writer->thread) {
        free(writer->thread);
        writer->thread = NULL;
    }
}

bool ddtrace_coms_on_pid_change(void) {
    struct _writer_loop_data_t *writer = _dd_get_writer();

    pid_t current_pid = getpid();
    pid_t previous_pid = atomic_load(&writer->current_pid);
    if (current_pid == previous_pid) {
        return true;
    }

    // ensure this reinitialization is done only once on pid change
    if (atomic_compare_exchange_strong(&writer->current_pid, &previous_pid, current_pid)) {
        if (writer->thread) {
            free(writer->thread);
            writer->thread = NULL;
        }

        ddtrace_coms_init_and_start_writer();
        return true;
    }

    return false;
}

bool ddtrace_coms_trigger_writer_flush(void) {
    struct _writer_loop_data_t *writer = _dd_get_writer();
    if (writer->thread) {
        pthread_mutex_lock(&writer->thread->interval_flush_mutex);
        pthread_cond_signal(&writer->thread->interval_flush_condition);
        pthread_mutex_unlock(&writer->thread->interval_flush_mutex);
    }

    return true;
}

void ddtrace_coms_rshutdown(void) {
    struct _writer_loop_data_t *writer = _dd_get_writer();

    atomic_fetch_add(&writer->request_counter, 1);

    /* atomic_fetch_add returns the old value, so +1 to get the current value;
     * this allows DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS=1 act almost
     * synchronously for our test suite
     */
    uint32_t requests_since_last_flush = atomic_fetch_add(&writer->requests_since_last_flush, 1) + 1;

    // simple heuristic to flush every n request to improve memory used
    if (requests_since_last_flush > get_DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS()) {
        ddtrace_coms_trigger_writer_flush();
    }
}

// Returns true if writer is shutdown completely
bool ddtrace_coms_flush_shutdown_writer_synchronous(void) {
    struct _writer_loop_data_t *writer = _dd_get_writer();
    if (!writer->thread) {
        return true;
    }

    _dd_writer_set_shutdown_state(writer);

    // wait for writer cycle to to complete before exiting
    pthread_mutex_lock(&writer->thread->writer_shutdown_signal_mutex);
    ddtrace_coms_trigger_writer_flush();

    bool should_join = false;
    // see _dd_signal_writer_started
    if (atomic_load(&writer->starting_up) || atomic_load(&writer->running)) {
        struct timespec deadline = _dd_deadline_in_ms(get_global_DD_TRACE_SHUTDOWN_TIMEOUT());

        int rv = pthread_cond_timedwait(&writer->thread->writer_shutdown_signal_condition,
                                        &writer->thread->writer_shutdown_signal_mutex, &deadline);
        if (rv == SUCCESS || rv == ETIMEDOUT) {
            if (rv == SUCCESS) {
                /* signalled, the writer thread finished */
                should_join = true;
            } else if (rv == ETIMEDOUT) {
                /* if this is not a fork, and timeout has been reached,
                    the thread needs to be cancelled and joined as this
                    is the last opportunity to join */
                if (!_dd_has_pid_changed()) {
                    pthread_cancel(writer->thread->self);

                    should_join = true;
                }
            }
        }
    } else {
        should_join = true;
    }
    pthread_mutex_unlock(&writer->thread->writer_shutdown_signal_mutex);

    if (should_join && !_dd_has_pid_changed()) {
        // when timeout was not reached and we haven't forked (without restarting thread)
        // this ensures situation when join is safe from being deadlocked
        pthread_join(writer->thread->self, NULL);
        free(writer->thread);
        writer->thread = NULL;
        return true;
    }
    return false;
}

bool ddtrace_coms_synchronous_flush(uint32_t timeout) {
    struct _writer_loop_data_t *writer = _dd_get_writer();
    uint32_t previous_writer_cycle = atomic_load(&writer->writer_cycle);
    uint32_t previous_processed_stacks_total = atomic_load(&writer->flush_processed_stacks_total);
    int64_t old_flush_interval = atomic_load(&writer->flush_interval);

    // ensure we immediately flush all data
    atomic_store(&writer->flush_interval, 0);

    pthread_mutex_lock(&writer->thread->finished_flush_mutex);
    ddtrace_coms_trigger_writer_flush();

    while (previous_writer_cycle == atomic_load(&writer->writer_cycle)) {
        if (!atomic_load(&writer->running) || !writer->thread) {
            // writer stopped there is no way the counter will be increaseed
            break;
        }
        struct timespec deadline = _dd_deadline_in_ms(timeout);
        pthread_cond_timedwait(&writer->thread->finished_flush_condition, &writer->thread->finished_flush_mutex,
                               &deadline);
    }
    pthread_mutex_unlock(&writer->thread->finished_flush_mutex);

    // restore the flush interval
    atomic_store(&writer->flush_interval, old_flush_interval);

    uint32_t processed_stacks_total =
        atomic_load(&writer->flush_processed_stacks_total) - previous_processed_stacks_total;

    return processed_stacks_total > 0;
}

bool ddtrace_in_writer_thread(void) {
    struct _writer_loop_data_t *writer = _dd_get_writer();
    if (!writer->thread) {
        return false;
    }

    return (pthread_self() == writer->thread->self);
}

/* for testing {{{ */
#define DDTRACE_NUMBER_OF_DATA_TO_WRITE 2000
#define DDTRACE_DATA_TO_WRITE "0123456789"

static void *_dd_test_writer_function(void *_) {
    (void)_;
    for (int i = 0; i < DDTRACE_NUMBER_OF_DATA_TO_WRITE; i++) {
        ddtrace_coms_buffer_data(0, DDTRACE_DATA_TO_WRITE, sizeof(DDTRACE_DATA_TO_WRITE) - 1);
    }
    pthread_exit(NULL);
    return NULL;
}

uint32_t ddtrace_coms_test_writers(void) {
    int threads = 100;

    pthread_t *thread = malloc(sizeof(pthread_t) * threads);

    for (int i = 0; i < threads; i++) {
        int ret = pthread_create(&thread[i], NULL, &_dd_test_writer_function, NULL);

        if (ret != 0) {
            printf("Create pthread error!\n");
        }
    }

    for (int i = 0; i < threads; i++) {
        void *ptr;
        pthread_join(thread[i], &ptr);
    }
    printf("written %lu\n",
           DDTRACE_NUMBER_OF_DATA_TO_WRITE * threads * (sizeof(DDTRACE_DATA_TO_WRITE) - 1 + sizeof(size_t)));
    fflush(stdout);
    free(thread);

    return 1;
}

uint32_t ddtrace_coms_test_consumer(void) {
    if (!_dd_coms_unsafe_rotate_stack(true, atomic_load(&ddtrace_coms_globals.stack_size))) {
        printf("error rotating stacks");
    }

    for (int i = 0; i < DDTRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        ddtrace_coms_stack_t *stack = ddtrace_coms_globals.stacks[i];
        if (!stack) continue;

        if (!ddtrace_coms_is_stack_unused(stack)) {
            continue;
        }

        size_t bytes_written = atomic_load(&stack->bytes_written);

        size_t position = 0;

        while (position < bytes_written) {
            size_t size = 0;
            memcpy(&size, stack->data + position, sizeof(size_t));

            position += sizeof(size_t) + sizeof(uint32_t);
            if (size == 0) {
            }
            char *data = stack->data + position;
            position += size;
            if (strncmp(data, "0123456789", sizeof("0123456789") - 1) != 0) {
                printf("%.*s\n", (int)size, data);
            }
        }
        printf("bytes_written %lu\n", bytes_written);
    }

    return 1;
}

#define PRINT_PRINTABLE(with_prefix, previous_ch, ch)           \
    do {                                                        \
        if (ch >= 0x20 && ch < 0x7f) {                          \
            if (!(previous_ch >= 0x20 && previous_ch < 0x7f)) { \
                if (with_prefix) {                              \
                    printf(" ");                                \
                }                                               \
            }                                                   \
            printf("%c", ch);                                   \
        } else {                                                \
            if (with_prefix) {                                  \
                printf(" %02hhX", ch);                          \
            } else {                                            \
                printf("%02hhX", ch);                           \
            }                                                   \
        }                                                       \
    } while (0)

uint32_t ddtrace_coms_test_msgpack_consumer(void) {
    _dd_coms_unsafe_rotate_stack(true, atomic_load(&ddtrace_coms_globals.stack_size));

    ddtrace_coms_stack_t *stack = _dd_coms_attempt_acquire_stack();
    if (!stack) {
        return 0;
    }
    void *userdata = _dd_init_read_userdata(stack);

    char *data = calloc(100000, 1);

    size_t written = _dd_coms_read_callback(data, 1, 1000, userdata);
    if (written > 0) {
        PRINT_PRINTABLE("", 0, data[0]);
        for (size_t i = 1; i < written; i++) {
            PRINT_PRINTABLE(" ", data[i - 1], data[i]);
        }
    }

    printf("\n");

    free(data);
    _dd_deinit_read_userdata(userdata);
    _dd_coms_free_stack(stack);
    return 1;
}
/* }}} */
