#include "handlers_internal.h"

void ddtrace_mongodb_handlers_startup(void) {
    // clang-format off
    ddtrace_string methods[] = {
        DDTRACE_STRING_LITERAL("__construct"),
        DDTRACE_STRING_LITERAL("executebulkwrite"),
        DDTRACE_STRING_LITERAL("selectserver"),
        DDTRACE_STRING_LITERAL("executequery"),
        DDTRACE_STRING_LITERAL("executecommand"),
        DDTRACE_STRING_LITERAL("executereadcommand"),
        DDTRACE_STRING_LITERAL("executewritecommand"),
        DDTRACE_STRING_LITERAL("executereadwritecommand"),
        DDTRACE_STRING_LITERAL("executebulkwrite"),
        DDTRACE_STRING_LITERAL("insert"),
        DDTRACE_STRING_LITERAL("delete"),
        DDTRACE_STRING_LITERAL("update"),
    };
    // clang-format on

    ddtrace_string mongodb_manager = DDTRACE_STRING_LITERAL("mongodb\\driver\\manager");
    ddtrace_string mongodb_server = DDTRACE_STRING_LITERAL("mongodb\\driver\\server");
    ddtrace_string mongodb_query = DDTRACE_STRING_LITERAL("mongodb\\driver\\query");
    ddtrace_string mongodb_command = DDTRACE_STRING_LITERAL("mongodb\\driver\\command");
    ddtrace_string mongodb_bulkwrite = DDTRACE_STRING_LITERAL("mongodb\\driver\\bulkwrite");
    size_t methods_len = sizeof methods / sizeof methods[0];
    ddtrace_replace_internal_methods(mongodb_manager, methods_len, methods);
    ddtrace_replace_internal_methods(mongodb_server, methods_len, methods);
    ddtrace_replace_internal_methods(mongodb_query, methods_len, methods);
    ddtrace_replace_internal_methods(mongodb_command, methods_len, methods);
    ddtrace_replace_internal_methods(mongodb_bulkwrite, methods_len, methods);
}
