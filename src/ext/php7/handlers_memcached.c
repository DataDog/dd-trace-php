#include "handlers_internal.h"

void ddtrace_memcached_handlers_startup(void) {
    // clang-format off
    ddtrace_string methods[] = {
        DDTRACE_STRING_LITERAL("add"),
        DDTRACE_STRING_LITERAL("addbykey"),
        DDTRACE_STRING_LITERAL("append"),
        DDTRACE_STRING_LITERAL("appendbykey"),
        DDTRACE_STRING_LITERAL("cas"),
        DDTRACE_STRING_LITERAL("casbykey"),
        DDTRACE_STRING_LITERAL("decrement"),
        DDTRACE_STRING_LITERAL("decrementbykey"),
        DDTRACE_STRING_LITERAL("delete"),
        DDTRACE_STRING_LITERAL("deletebykey"),
        DDTRACE_STRING_LITERAL("deletemulti"),
        DDTRACE_STRING_LITERAL("deletemultibykey"),
        DDTRACE_STRING_LITERAL("flush"),
        DDTRACE_STRING_LITERAL("get"),
        DDTRACE_STRING_LITERAL("getbykey"),
        DDTRACE_STRING_LITERAL("getmulti"),
        DDTRACE_STRING_LITERAL("getmultibykey"),
        DDTRACE_STRING_LITERAL("increment"),
        DDTRACE_STRING_LITERAL("incrementbykey"),
        DDTRACE_STRING_LITERAL("prepend"),
        DDTRACE_STRING_LITERAL("prependbykey"),
        DDTRACE_STRING_LITERAL("replace"),
        DDTRACE_STRING_LITERAL("replacebykey"),
        DDTRACE_STRING_LITERAL("set"),
        DDTRACE_STRING_LITERAL("setbykey"),
        DDTRACE_STRING_LITERAL("setmulti"),
        DDTRACE_STRING_LITERAL("setmultibykey"),
        DDTRACE_STRING_LITERAL("touch"),
        DDTRACE_STRING_LITERAL("touchbykey"),
    };
    // clang-format on

    ddtrace_string memcached = DDTRACE_STRING_LITERAL("memcached");
    size_t methods_len = sizeof methods / sizeof methods[0];
    ddtrace_replace_internal_methods(memcached, methods_len, methods);
}
