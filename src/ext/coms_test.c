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
        dd_trace_coms_flush_data(DDTRACE_DATA_TO_WRITE, sizeof(DDTRACE_DATA_TO_WRITE) - 1);
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
        //error
    }

    for(int i = 0; i< DD_TRACE_COMS_STACKS_BACKLOG_SIZE; i++) {
        dd_trace_coms_stack_t *stack = dd_trace_coms_global_state.stacks[i];
        if (!stack) continue;
        size_t bytes_written = atomic_load(&stack->bytes_written);

        if (!stack || !dd_trace_coms_is_stack_unused(stack)){
            continue;
        }

        size_t position = 0;

        while (position < bytes_written) {
            size_t size = 0;
            memcpy(&size, stack->data + position, sizeof(size_t));

            position += sizeof(size_t);
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


