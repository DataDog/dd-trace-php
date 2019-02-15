#ifndef DD_DEBUG_BACKTRACE_H
#define DD_DEBUG_BACKTRACE_H
#define MAX_STACK_SIZE 100
void ddtrace_backtrace_handler(int sig);
void ddtrace_install_backtrace_handler();

#endif  // DD_DEBUG_H
