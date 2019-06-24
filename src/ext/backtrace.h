#ifndef DD_BACKTRACE_H
#define DD_BACKTRACE_H
#define MAX_STACK_SIZE 1024
#if defined(__GLIBC__) || defined(__APPLE__)
void ddtrace_backtrace_handler(int sig);
#endif
void ddtrace_install_backtrace_handler();

#endif  // DD_BACKTRACE_H
