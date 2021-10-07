#include "handlers_internal.h"

void ddtrace_mongodb_handlers_startup(void) {
    // clang-format off
    ddtrace_string methods[] = {
        DDTRACE_STRING_LITERAL("__construct"),
        DDTRACE_STRING_LITERAL("executebulkwrite"),
    };
    // clang-format on

    ddtrace_string mongodb_manager = DDTRACE_STRING_LITERAL("mongodb\\driver\\manager");
    ddtrace_string mongodb_server = DDTRACE_STRING_LITERAL("mongodb\\driver\\server");
    size_t methods_len = sizeof methods / sizeof methods[0];
    ddtrace_replace_internal_methods(mongodb_manager, methods_len, methods);
    ddtrace_replace_internal_methods(mongodb_server, methods_len, methods);
}
