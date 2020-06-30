#ifndef DD_INTEGRATIONS_INTEGRATIONS_H
#define DD_INTEGRATIONS_INTEGRATIONS_H
#include <php.h>
#include "dispatch.h"
#include "ddtrace_string.h"

#define DDTRACE_DEFERED_INTEGRATION_LOADER(class_str, fname_str, loader_str)                    \
    ddtrace_hook_callable(DDTRACE_STRING_LITERAL(class_str), DDTRACE_STRING_LITERAL(fname_str), \
                          DDTRACE_STRING_LITERAL(loader_str), DDTRACE_DISPATCH_DEFERED_LOADER)

void dd_integrations_initialize(TSRMLS_D);
#endif
