#include "handlers_internal.h"

void ddtrace_phpredis_handlers_startup(void) {
    // clang-format off
    ddtrace_string methods[] = {
        DDTRACE_STRING_LITERAL("auth"),
        DDTRACE_STRING_LITERAL("bgrewriteaof"),
        DDTRACE_STRING_LITERAL("bgsave"),
        DDTRACE_STRING_LITERAL("close"),
        DDTRACE_STRING_LITERAL("connect"),
        DDTRACE_STRING_LITERAL("echo"),
        DDTRACE_STRING_LITERAL("flushall"),
        DDTRACE_STRING_LITERAL("flushdb"),
        DDTRACE_STRING_LITERAL("open"),
        DDTRACE_STRING_LITERAL("pconnect"),
        DDTRACE_STRING_LITERAL("ping"),
        DDTRACE_STRING_LITERAL("popen"),
        DDTRACE_STRING_LITERAL("save"),
        DDTRACE_STRING_LITERAL("select"),

        DDTRACE_STRING_LITERAL("append"),
        DDTRACE_STRING_LITERAL("decr"),
        DDTRACE_STRING_LITERAL("decrby"),
        DDTRACE_STRING_LITERAL("get"),
        DDTRACE_STRING_LITERAL("getbit"),
        DDTRACE_STRING_LITERAL("getrange"),
        DDTRACE_STRING_LITERAL("getset"),
        DDTRACE_STRING_LITERAL("incr"),
        DDTRACE_STRING_LITERAL("incrby"),
        DDTRACE_STRING_LITERAL("incrbyfloat"),
    };
    // clang-format on

    ddtrace_string phpredis = DDTRACE_STRING_LITERAL("redis");
    size_t methods_len = sizeof methods / sizeof methods[0];
    ddtrace_replace_internal_methods(phpredis, methods_len, methods);
}
