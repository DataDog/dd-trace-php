#include "handlers_internal.h"

// clang-format off
static ddtrace_string ddtrace_pdo_methods[] = {
    DDTRACE_STRING_LITERAL("__construct"),
    DDTRACE_STRING_LITERAL("commit"),
    DDTRACE_STRING_LITERAL("exec"),
    DDTRACE_STRING_LITERAL("prepare"),
    DDTRACE_STRING_LITERAL("query"),
};

static ddtrace_string ddtrace_pdostatement_methods[] = {
    DDTRACE_STRING_LITERAL("execute"),
};
// clang-format on

void ddtrace_pdo_handlers_startup(void) {
    ddtrace_string pdo = DDTRACE_STRING_LITERAL("pdo");
    if (!zend_hash_str_exists(&module_registry, pdo.ptr, pdo.len)) {
        return;
    }

    size_t pdo_methods_len = sizeof ddtrace_pdo_methods / sizeof ddtrace_pdo_methods[0];
    ddtrace_replace_internal_methods(pdo, pdo_methods_len, ddtrace_pdo_methods);

    ddtrace_string pdostatement = DDTRACE_STRING_LITERAL("pdostatement");
    size_t pdostatement_methods_len = sizeof ddtrace_pdostatement_methods / sizeof ddtrace_pdostatement_methods[0];
    ddtrace_replace_internal_methods(pdostatement, pdostatement_methods_len, ddtrace_pdostatement_methods);
}

void ddtrace_pdo_handlers_shutdown(void) {}
