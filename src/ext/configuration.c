#include <stdlib.h>

#include "configuration.h"
#include "env_config.h"

struct ddtrace_memoized_configuration_t ddtrace_memoized_configuration = {0};

void ddtrace_initialize_config() {
    // read all values to memoize them

    // CHAR returns a copy of a value that we need to free
#define CHAR(getter_name, ...)   \
    do {                         \
        char *p = getter_name(); \
        if (p) {                 \
            free(p);             \
        }                        \
    } while (0);
#define INT(getter_name, ...) getter_name();
#define BOOL(getter_name, ...) getter_name();

    DD_CONFIGURATION

#undef CHAR
#undef INT
#undef BOOL
}

void ddtrace_reload_config() {
#define CHAR(getter_name, ...)                            \
    if (ddtrace_memoized_configuration.getter_name) {     \
        free(ddtrace_memoized_configuration.getter_name); \
    }                                                     \
    ddtrace_memoized_configuration.__is_set_##getter_name = FALSE;
#define INT(getter_name, ...) ddtrace_memoized_configuration.__is_set_##getter_name = FALSE;
#define BOOL(getter_name, ...) ddtrace_memoized_configuration.__is_set_##getter_name = FALSE;

    DD_CONFIGURATION

#undef CHAR
#undef INT
#undef BOOL
    // repopulate config
    ddtrace_initialize_config();
}
