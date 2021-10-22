#include "handlers_internal.h"

static void register_handles_manager_and_server(void) {
    // clang-format off
    ddtrace_string methods[] = {
        DDTRACE_STRING_LITERAL("__construct"),
        DDTRACE_STRING_LITERAL("selectserver"),
        DDTRACE_STRING_LITERAL("executequery"),
        DDTRACE_STRING_LITERAL("executecommand"),
        DDTRACE_STRING_LITERAL("executereadcommand"),
        DDTRACE_STRING_LITERAL("executewritecommand"),
        DDTRACE_STRING_LITERAL("executereadwritecommand"),
        DDTRACE_STRING_LITERAL("executebulkwrite"),
    };
    // clang-format on

    ddtrace_string manager = DDTRACE_STRING_LITERAL("mongodb\\driver\\manager");
    ddtrace_string server = DDTRACE_STRING_LITERAL("mongodb\\driver\\server");
    size_t methods_len = sizeof methods / sizeof methods[0];
    ddtrace_replace_internal_methods(manager, methods_len, methods);
    ddtrace_replace_internal_methods(server, methods_len, methods);
}

static void register_handles_query(void) {
    // clang-format off
    ddtrace_string methods[] = {
        DDTRACE_STRING_LITERAL("__construct"),
    };
    // clang-format on

    ddtrace_string class = DDTRACE_STRING_LITERAL("mongodb\\driver\\query");
    size_t methods_len = sizeof methods / sizeof methods[0];
    ddtrace_replace_internal_methods(class, methods_len, methods);
}

static void register_handles_command(void) {
    // clang-format off
    ddtrace_string methods[] = {
        DDTRACE_STRING_LITERAL("__construct"),
    };
    // clang-format on

    ddtrace_string class = DDTRACE_STRING_LITERAL("mongodb\\driver\\command");
    size_t methods_len = sizeof methods / sizeof methods[0];
    ddtrace_replace_internal_methods(class, methods_len, methods);
}

static void register_handles_bulkwrite(void) {
    // clang-format off
    ddtrace_string methods[] = {
        DDTRACE_STRING_LITERAL("__construct"),
        DDTRACE_STRING_LITERAL("insert"),
        DDTRACE_STRING_LITERAL("delete"),
        DDTRACE_STRING_LITERAL("update"),
    };
    // clang-format on

    ddtrace_string class = DDTRACE_STRING_LITERAL("mongodb\\driver\\bulkwrite");
    size_t methods_len = sizeof methods / sizeof methods[0];
    ddtrace_replace_internal_methods(class, methods_len, methods);
}

void ddtrace_mongodb_handlers_startup(void) {
    register_handles_manager_and_server();
    register_handles_query();
    register_handles_bulkwrite();
    register_handles_command();
}
