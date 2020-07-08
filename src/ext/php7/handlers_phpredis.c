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
        DDTRACE_STRING_LITERAL("set"),
        DDTRACE_STRING_LITERAL("setbit"),
        DDTRACE_STRING_LITERAL("setex"),
        DDTRACE_STRING_LITERAL("psetex"),
        DDTRACE_STRING_LITERAL("setnx"),
        DDTRACE_STRING_LITERAL("setrange"),
        DDTRACE_STRING_LITERAL("strlen"),

        DDTRACE_STRING_LITERAL("del"),
        DDTRACE_STRING_LITERAL("delete"),
        DDTRACE_STRING_LITERAL("dump"),
        DDTRACE_STRING_LITERAL("exists"),
        DDTRACE_STRING_LITERAL("keys"),
        DDTRACE_STRING_LITERAL("getKeys"),
        DDTRACE_STRING_LITERAL("scan"),
        DDTRACE_STRING_LITERAL("migrate"),
        DDTRACE_STRING_LITERAL("move"),
        DDTRACE_STRING_LITERAL("persist"),
        DDTRACE_STRING_LITERAL("rename"),
        DDTRACE_STRING_LITERAL("renameKey"),
        DDTRACE_STRING_LITERAL("renameNx"),
        DDTRACE_STRING_LITERAL("type"),
        DDTRACE_STRING_LITERAL("sort"),
        DDTRACE_STRING_LITERAL("restore"),
    };
    // clang-format on

    ddtrace_string phpredis = DDTRACE_STRING_LITERAL("redis");
    size_t methods_len = sizeof methods / sizeof methods[0];
    ddtrace_replace_internal_methods(phpredis, methods_len, methods);
}
