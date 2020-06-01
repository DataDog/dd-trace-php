#include "handlers_internal.h"

// clang-format off
static ddtrace_string ddtrace_mysqli_functions[] = {
    DDTRACE_STRING_LITERAL("mysqli_commit"),
    DDTRACE_STRING_LITERAL("mysqli_connect"),
    DDTRACE_STRING_LITERAL("mysqli_prepare"),
    DDTRACE_STRING_LITERAL("mysqli_query"),
    DDTRACE_STRING_LITERAL("mysqli_real_connect"),
    DDTRACE_STRING_LITERAL("mysqli_stmt_execute"),
    DDTRACE_STRING_LITERAL("mysqli_stmt_get_result"),
};

static ddtrace_string ddtrace_mysqli_methods[] = {
    DDTRACE_STRING_LITERAL("__construct"),
    DDTRACE_STRING_LITERAL("commit"),
    DDTRACE_STRING_LITERAL("prepare"),
    DDTRACE_STRING_LITERAL("query"),
    DDTRACE_STRING_LITERAL("real_connect"),
};

static ddtrace_string ddtrace_mysqli_stmt_methods[] = {
    DDTRACE_STRING_LITERAL("execute"),
    DDTRACE_STRING_LITERAL("get_result"),
};
// clang-format on

void ddtrace_mysqli_handlers_startup(void) {
    // todo: how to handle ddtrace_resource = -1?
    ddtrace_string mysqli = DDTRACE_STRING_LITERAL("mysqli");
    if (!zend_hash_str_exists(&module_registry, mysqli.ptr, mysqli.len)) {
        return;
    }

    size_t mysqli_functions_len = sizeof ddtrace_mysqli_functions / sizeof ddtrace_mysqli_functions[0];
    ddtrace_replace_internal_functions(CG(function_table), mysqli_functions_len, ddtrace_mysqli_functions);

    size_t mysqli_methods_len = sizeof ddtrace_mysqli_methods / sizeof ddtrace_mysqli_methods[0];
    ddtrace_replace_internal_methods(mysqli, mysqli_methods_len, ddtrace_mysqli_methods);

    ddtrace_string mysqli_stmt = DDTRACE_STRING_LITERAL("mysqli_stmt");
    size_t mysqli_stmt_methods_len = sizeof ddtrace_mysqli_stmt_methods / sizeof ddtrace_mysqli_stmt_methods[0];
    ddtrace_replace_internal_methods(mysqli_stmt, mysqli_stmt_methods_len, ddtrace_mysqli_stmt_methods);
}

void ddtrace_mysqli_handlers_shutdown(void) {}
