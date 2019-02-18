#ifndef DD_BACKTRACE_H
#define DD_BACKTRACE_H
#define MAX_STACK_SIZE 1024
void ddtrace_backtrace_handler(int sig);
void ddtrace_install_backtrace_handler(TSRMLS_D);

#endif  // DD_BACKTRACE_H
