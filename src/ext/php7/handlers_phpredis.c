#include "handlers_internal.h"

void ddtrace_phpredis_handlers_startup(void) {
    // clang-format off
    ddtrace_string methods[] = {
        DDTRACE_STRING_LITERAL("connect"),
    };
    // clang-format on

    ddtrace_string phpredis = DDTRACE_STRING_LITERAL("redis");
    size_t methods_len = sizeof methods / sizeof methods[0];
    ddtrace_replace_internal_methods(phpredis, methods_len, methods);
}
