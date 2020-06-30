#include "defered.h"

#include "ddtrace_string.h"
#include "dispatch.h"

void dd_load_defered_integration_list(ddtrace_defered_integration *list, size_t size) {
    for (size_t i = 0; i < size; ++i) {
        ddtrace_defered_integration integration = list[i];
        ddtrace_hook_callable(integration.class_name, integration.fname, integration.loader, DDTRACE_DISPATCH_DEFERED_LOADER);
    }
}
