#include <stdlib.h>

#include "configuration.h"
#include "env_config.h"

struct ddtrace_memoized_configuration_t ddtrace_memoized_configuration = {
#define CHAR(...) NULL, FALSE,
#define BOOL(...) FALSE, FALSE,
#define INT(...) 0, FALSE,
    DD_CONFIGURATION
#undef CHAR
#undef BOOL
#undef INT
        PTHREAD_MUTEX_INITIALIZER};

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
