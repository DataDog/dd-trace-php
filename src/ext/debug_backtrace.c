#include <stdio.h>
#include <execinfo.h>
#include <signal.h>
#include <stdlib.h>
#include <unistd.h>
#include "debug_backtrace.h"

void ddtrace_backtrace_handler(int sig) {
    fprintf(stderr, "Datadog PHP Trace extension (DEBUG MODE)\n");
    fprintf(stderr, "Received Signal %d\n", sig);
    void *array[MAX_STACK_SIZE];
    size_t size = backtrace(array, MAX_STACK_SIZE);

    fprintf(stderr, "Note: Backtrace below might be incomplete and have wrong entries due to optimized runtime\n");
    fprintf(stderr, "Backtrace: \n");
    backtrace_symbols_fd(array, size, STDERR_FILENO);
    fflush(stderr);

    // exit(1);
}

void ddtrace_install_backtrace_handler(){
    static int handler_installed = 0;
    if (!handler_installed){
        fflush(stderr);

        signal(SIGSEGV, ddtrace_backtrace_handler);
        handler_installed = 1;
    }
}

