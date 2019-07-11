#include "coms_debug.h"

#include <pthread.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "coms.h"

#define DDTRACE_NUMBER_OF_DATA_TO_WRITE 2000
#define DDTRACE_DATA_TO_WRITE "0123456789"

extern ddtrace_coms_state_t ddtrace_coms_global_state;

static void *test_writer_function(void *_) {
    (void)_;
    for (int i = 0; i < DDTRACE_NUMBER_OF_DATA_TO_WRITE; i++) {
        ddtrace_coms_buffer_data(0, DDTRACE_DATA_TO_WRITE, sizeof(DDTRACE_DATA_TO_WRITE) - 1);
    }
    pthread_exit(NULL);
    return NULL;
}

uint32_t ddtrace_coms_test_writers() {
    int threads = 100, ret = -1;

    pthread_t *thread = malloc(sizeof(pthread_t) * threads);

    for (int i = 0; i < threads; i++) {
        ret = pthread_create(&thread[i], NULL, &test_writer_function, NULL);

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

uint32_t ddtrace_coms_test_consumer() {
    if (!ddtrace_coms_rotate_stack(TRUE)) {
        printf("error rotating stacks");
    }

    for (int i = 0; i < DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        ddtrace_coms_stack_t *stack = ddtrace_coms_global_state.stacks[i];
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

uint32_t ddtrace_coms_test_msgpack_consumer() {
    ddtrace_coms_rotate_stack(TRUE);

    ddtrace_coms_stack_t *stack = ddtrace_coms_attempt_acquire_stack();
    if (!stack) {
        return 0;
    }
    void *userdata = ddtrace_init_read_userdata(stack);

    char *data = calloc(100000, 1);

    size_t written = ddtrace_coms_read_callback(data, 1, 1000, userdata);
    if (written > 0) {
        PRINT_PRINTABLE("", 0, data[0]);
        for (size_t i = 1; i < written; i++) {
            PRINT_PRINTABLE(" ", data[i - 1], data[i]);
        }
    }

    printf("\n");

    free(data);
    ddtrace_deinit_read_userdata(userdata);
    ddtrace_coms_free_stack(stack);
    return 1;
}
