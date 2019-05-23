#include <pthread.h>
#include <stdio.h>
#include <string.h>
#include <stdlib.h>

#include "coms.h"
#include "coms_test.h"

#define DDTRACE_NUMBER_OF_DATA_TO_WRITE 2000
#define DDTRACE_DATA_TO_WRITE "0123456789"

extern dd_trace_coms_state_t dd_trace_coms_global_state;

static void *test_writer_function(void *_){
    (void)_;
    for (int i =0; i < DDTRACE_NUMBER_OF_DATA_TO_WRITE; i++) {
        dd_trace_coms_flush_data(0, DDTRACE_DATA_TO_WRITE, sizeof(DDTRACE_DATA_TO_WRITE) - 1);
    }
    pthread_exit(NULL);
    return NULL;
}

uint32_t dd_trace_coms_test_writers() {
    int threads = 100, ret = -1;

    pthread_t *thread = malloc(sizeof(pthread_t)*threads);

    for (int i = 0; i < threads; i++) {
        ret = pthread_create(&thread[i], NULL, &test_writer_function, NULL);

        if(ret != 0) {
            printf("Create pthread error!\n");
        }
    }

    for(int i=0; i< threads; i++) {
        void *ptr;
        pthread_join(thread[i], &ptr);
    }
    printf("written %lu\n", DDTRACE_NUMBER_OF_DATA_TO_WRITE * threads * (sizeof(DDTRACE_DATA_TO_WRITE) - 1 + sizeof(size_t)));
    fflush(stdout);

    return 1;
}

uint32_t dd_trace_coms_test_consumer(){
    if (dd_trace_coms_rotate_stack() != 0) {
        printf("error rotating stacks");
    }

    for(int i = 0; i< DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        dd_trace_coms_stack_t *stack = dd_trace_coms_global_state.stacks[i];
        if (!stack) continue;

        if (!dd_trace_coms_is_stack_unused(stack)){
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
            if (strncmp(data, "0123456789", sizeof("0123456789") - 1) != 0){
                printf("%.*s\n",(int) size, data);
            }
        }
        printf("bytes_written %lu\n", bytes_written);
    }


    return 1;
}


#define PRINT_PRINTABLE(prefix, previous_ch, ch) do {\
        if (ch >= 0x20 && ch < 0x7f) { \
            if (!(previous_ch >= 0x20 && previous_ch < 0x7f)) { \
                printf(prefix); \
            } \
            printf("%c", ch); \
        } else { \
            printf(prefix "%02hhX", ch); \
        } \
    } while (0)

uint32_t dd_trace_coms_test_msgpack_consumer() {
    dd_trace_coms_rotate_stack();

    dd_trace_coms_stack_t *stack = dd_trace_coms_attempt_acquire_stack();
    if (!stack) {
        return;
    }
    void *userdata = dd_trace_init_read_userdata(stack);

    char *data = calloc(100000, 1);

    size_t written = dd_trace_coms_read_callback(data, 1, 1000, userdata);
    int should_print_space = 0;
    if (written > 0) {
        PRINT_PRINTABLE("", 0, data[0]);
        for (int i =1 ;i < written; i++) {
            PRINT_PRINTABLE(" ", data[i-1], data[i]);
        }
    }

    printf("\n");

    free(data);
    free(userdata);
}
